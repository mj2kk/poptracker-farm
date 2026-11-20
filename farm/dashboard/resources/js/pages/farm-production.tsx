import { Activity, ChartNoAxesColumnIncreasing, ChevronRight, Clock3, Globe2, History, LoaderCircle, MessagesSquare, Pause, Play, Radio, ScanSearch, Sparkles } from 'lucide-react';
import type { ReactNode } from 'react';
import { useRef, useState } from 'react';
import { base, FarmHttpError, post } from './farm-api';
import type { Device } from './farm-api';
import { CommandDialog } from './farm-command-dialog';
import { FeedDialog, feedIntentKey, readFeedIntent } from './farm-feed-dialog';
import type { FeedIntent, FeedSelection } from './farm-feed-dialog';
import { commandLabels, displayCount, Help, RunList, states } from './farm-production-view';
import type { CollectionCommand, Job, JobState } from './farm-production-view';

export { commandLabels } from './farm-production-view';

const metrics = [
    { key: 'za_authors', label: 'Авторы из ЮАР', help: 'Авторы в базе, для которых проверка страны подтвердила ЮАР.' },
    { key: 'geo_candidates', label: 'Авторы с неопределённой страной', help: 'Новые авторы, найденные в ленте. Их страна ещё ожидает проверки.' },
    { key: 'videos', label: 'Видео в базе', help: 'Уникальные видео в каталоге и импортированной ленте. Повторно найденное видео учитывается один раз.' },
] as const;

const icons: Record<string, typeof Radio> = {
    IMPORT_FEED: Radio, VALIDATE_GEO: Globe2, DISCOVER_VIDEOS: ScanSearch,
    REFRESH_METRICS: ChartNoAxesColumnIncreasing, EVALUATE_VIDEO: Sparkles, COLLECT_COMMENTS: MessagesSquare,
};

type OverviewField = typeof metrics[number]['key'] | 'observations' | 'ai_selected' | 'commented_videos';
export type ProductionSnapshot = {
    sampled_at: string; summary_at: string | null; devices: Device[];
    overview: Record<OverviewField, number | null>;
    status: Record<JobState, number> & { paused: boolean; last_success_at: string | null };
    commands: CollectionCommand[]; jobs: Job[];
    analytics: { available: boolean; reason: string | null; pending_targets: number | null; model: string | null };
    can_develop: boolean;
};

type CollectionProps = {
    data: ProductionSnapshot | null;
    live: boolean;
    refresh: () => Promise<void>;
    commandFilter: string;
    setCommandFilter: (value: string) => void;
    statusFilter: string;
    setStatusFilter: (value: string) => void;
    children: ReactNode;
};

export function CollectionPanel({
    data,
    live,
    refresh,
    commandFilter,
    setCommandFilter,
    statusFilter,
    setStatusFilter,
    children,
}: CollectionProps) {
    const [busy, setBusy] = useState<string | null>(null);
    const [notice, setNotice] = useState<{ message: string; error: boolean } | null>(null);
    const activeMutation = useRef(false);
    const [feedIntent, setFeedIntent] = useState<FeedIntent | null>(readFeedIntent);
    const [feedOpen, setFeedOpen] = useState(false);
    const [selection, setSelection] = useState<string | null>(null);
    const [historyOpen, setHistoryOpen] = useState(false);
    const [logVersion, setLogVersion] = useState(0);
    const commands = [...(data?.commands ?? [])];
    if (data?.analytics) {
        commands.push({
            type: 'EVALUATE_VIDEO', label: commandLabels.EVALUATE_VIDEO, description: '',
            available: data.analytics.available, reason: data.analytics.reason, pending_targets: null,
            last_run_at: null, last_result: null, progress: null,
        });
    }
    const orderedCommands = Object.keys(commandLabels)
        .map((type) => commands.find((command) => command.type === type))
        .filter((command): command is CollectionCommand => command != null);
    const command = commands.find((item) => item.type === selection);
    const Icon = icons[selection ?? ''] ?? Activity;
    const status = data?.status;
    const jobs = (data?.jobs ?? []).filter((job) => (!commandFilter || commandFilter === job.type) && (!statusFilter || statusFilter === job.status));
    const isFeed = command?.source_mode === 'apify';
    const retry = isFeed && feedIntent != null;
    const unavailable = !live ? 'Нет связи с сервером.' : retry ? null : status?.paused ? 'Очередь на паузе.'
        : command && !command.available ? command.reason || 'Команда временно недоступна.'
        : isFeed && !command?.pricing ? 'Не удалось получить стоимость сбора.' : null;

    function needsGeoSource(item: CollectionCommand) {
        return live && !status?.paused && item.type === 'VALIDATE_GEO' && !item.available
            && item.unavailable_code === 'geo_source_unavailable';
    }

    async function change(key: string, path: string, body: object, success: string, feedSelection?: FeedSelection) {
        if (!live || activeMutation.current) return;
        const apify = !!feedSelection;
        if (feedSelection) {
            try {
                const intent = feedIntent ?? { request_id: crypto.randomUUID(), ...feedSelection };
                sessionStorage.setItem(feedIntentKey, JSON.stringify(intent));
                setFeedIntent(intent);
                body = { ...body, source: 'apify', ...intent };
            } catch {
                setNotice({ message: 'Для запуска разрешите сайту хранить данные этой вкладки.', error: true });
                return;
            }
        }
        activeMutation.current = true;
        setBusy(key);
        setNotice(null);
        try {
            const response = await post<{ queued?: number }>(base + '/production/' + path, body);
            if (apify) {
                sessionStorage.removeItem(feedIntentKey);
                setFeedIntent(null);
            }
            const message =
                apify && response.queued === 0
                    ? 'Этот запуск уже принят. Результат появится в истории заданий.'
                    : typeof response.queued === 'number'
                    ? success + ' Заданий в очереди · ' + displayCount(response.queued)
                    : success;
            setNotice({ message, error: false });
        } catch (error) {
            const rejected = error instanceof FarmHttpError && [401, 403, 404, 409, 419, 422, 429].includes(error.status);
            if (apify && rejected) {
                sessionStorage.removeItem(feedIntentKey);
                setFeedIntent(null);
            }
            setNotice({
                message:
                    apify && !rejected
                        ? 'Запуск не подтверждён. Проверьте отправленный запрос этой же кнопкой.'
                        : error instanceof Error
                        ? error.message
                        : 'Не удалось подтвердить действие. Проверьте историю заданий.',
                error: true,
            });
        } finally {
            try {
                await refresh();
                setLogVersion((previous) => previous + 1);
            } finally {
                activeMutation.current = false;
                setBusy(null);
            }
        }
    }

    function launch() {
        if (!command || unavailable || busy) return;
        if (isFeed && !feedIntent) {
            setFeedOpen(true);
        } else if (command.type === 'EVALUATE_VIDEO') {
            void change(command.type, 'evaluations', {}, 'Видео переданы на оценку.');
        } else {
            void change(command.type, 'jobs', { type: command.type }, 'Запрос принят.', retry ? feedIntent ?? undefined : undefined);
        }
    }

    function cancel(job: Job) {
        void change(job.id, 'jobs/' + encodeURIComponent(job.id) + '/cancel', {}, 'Задание отменено.');
    }

    function showProblems(filter: JobState) {
        setCommandFilter('');
        setStatusFilter(filter);
        setHistoryOpen(true);
    }

    let queueStatus = 'Получаем состояние…';
    if (status) {
        const parts = [];
        if (status.paused) parts.push('Очередь на паузе');
        if (status.running) parts.push('В работе · ' + displayCount(status.running));
        if (status.pending) parts.push('Ожидают запуска · ' + displayCount(status.pending));
        queueStatus = parts.join(' · ') || 'Очередь свободна';
    }
    const actionLabel = retry ? 'Проверить отправленный запрос' : isFeed ? 'Выбрать количество' :
        command?.type === 'EVALUATE_VIDEO' ? 'Оценить видео' : 'Запустить';

    return (
        <div className="pf-collection">
            {command && <CommandDialog key={command.type} command={command} devices={data?.devices ?? []} icon={<Icon size={20} aria-hidden="true" />}
                close={() => { setSelection(null); setNotice(null); }} action={launch} actionLabel={actionLabel}
                disabledReason={unavailable} needsSource={needsGeoSource(command)} busy={busy !== null} offline={!live} notice={notice}
                version={logVersion} cancel={cancel} />}
            {feedOpen && command?.pricing && <FeedDialog pricing={command.pricing} close={() => setFeedOpen(false)}
                disabledReason={unavailable} confirm={(chosen) => {
                    setFeedOpen(false);
                    void change(command.type, 'jobs', { type: command.type }, 'Сбор ленты поставлен в очередь.', chosen);
                }} />}
            {notice && !command && <div className={'pf-notice ' + (notice.error ? 'pf-error' : '')}
                role={notice.error ? 'alert' : 'status'}>{notice.message}</div>}

            <section className="pf-summary" aria-label="Что в базе">
                <h2>Что в базе</h2>
                <div className="pf-summary-grid">{metrics.map((metric) => <article className="pf-stat" key={metric.key}>
                    <div className="pf-stat-label">{metric.label}<Help label={metric.label} text={metric.help} /></div>
                    <strong>{data?.overview[metric.key] != null ? displayCount(data.overview[metric.key]!) : '—'}</strong>
                </article>)}</div>
            </section>

            <section className="pf-actions" aria-label="Команды">
                <h2>Команды</h2>
                <ul className="pf-action-list">{orderedCommands.map((item) => {
                    const ActionIcon = icons[item.type];
                    return <li key={item.type}><button type="button" className="pf-action-row" aria-haspopup="dialog"
                        onClick={() => { setNotice(null); setSelection(item.type); }}>
                        <span className="pf-action-icon"><ActionIcon size={18} aria-hidden="true" /></span>
                        <span className="pf-action-name">{commandLabels[item.type]}
                            {needsGeoSource(item) && <small className="pf-action-hint">Источник не подключён</small>}
                        </span>
                        <ChevronRight size={18} aria-hidden="true" />
                    </button></li>;
                })}</ul>
                {!orderedCommands.length && <p className="pf-empty">{data ? 'Команды пока недоступны.' : 'Загружаем команды…'}</p>}
            </section>

            <section className="pf-work" aria-label="Очередь заданий">
                <div className="pf-work-bar">
                    <div className="pf-work-state"><Clock3 size={19} aria-hidden="true" /><h2>{queueStatus}</h2></div>
                    <div className="pf-work-controls">
                        <button type="button" className="pf-btn" aria-expanded={historyOpen} aria-controls="pf-queue-history"
                            onClick={() => setHistoryOpen(!historyOpen)}><History size={15} />История</button>
                        <button type="button" className="pf-btn" disabled={!live || !status || busy !== null}
                            onClick={() => void change('queue', 'queue', { paused: !status?.paused },
                                status?.paused ? 'Очередь возобновлена.' : 'Очередь на паузе.')}>
                            {busy === 'queue' ? <LoaderCircle size={14} className="pf-spin" /> : status?.paused ? <Play size={14} /> : <Pause size={14} />}
                            {status?.paused ? 'Возобновить' : 'Приостановить'}
                        </button>
                    </div>
                </div>
                {(!!status?.failed || !!status?.blocked) && <div className="pf-work-alerts">
                    {!!status?.failed && <button type="button" className="pf-more" onClick={() => showProblems('failed')}>
                        Ошибок в истории · {displayCount(status.failed)}</button>}
                    {!!status?.blocked && <button type="button" className="pf-more" onClick={() => showProblems('blocked')}>
                        Заблокировано в истории · {displayCount(status.blocked)}</button>}
                </div>}
                {historyOpen && <div id="pf-queue-history" className="pf-work-history">
                    <h3>История заданий</h3>
                    <div className="pf-filters">
                        <label>Команда<select value={commandFilter} onChange={(e) => setCommandFilter(e.target.value)}>
                            <option value="">Все команды</option>
                            {Object.entries(commandLabels).map(([key, name]) => <option key={key} value={key}>{name}</option>)}
                        </select></label>
                        <label>Статус<select value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)}>
                            <option value="">Все статусы</option>
                            {Object.entries(states).map(([key, name]) => <option key={key} value={key}>{name}</option>)}
                        </select></label>
                    </div>
                    {jobs.length ? <RunList key={commandFilter + '/' + statusFilter} jobs={jobs} devices={data?.devices ?? []} global busy={!live || busy !== null} cancel={cancel} />
                        : <p className="pf-empty">{commandFilter || statusFilter ? 'Нет заданий с выбранными параметрами.' : 'Заданий пока нет.'}</p>}
                </div>}
            </section>
            {children}
        </div>
    );
}
