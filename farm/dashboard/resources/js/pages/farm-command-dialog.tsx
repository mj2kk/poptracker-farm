import { LoaderCircle, X } from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useRef, useState } from 'react';
import { api, base } from './farm-api';
import type { Device } from './farm-api';
import { GeoPreview } from './farm-geo-preview';
import { commandLabels, displayCount, RunList } from './farm-production-view';
import type { CollectionCommand, Job } from './farm-production-view';

const descriptions: Record<string, string> = {
    IMPORT_FEED: 'Собирает свежие публикации из ленты TikTok ЮАР за последние семь дней. Стоимость появится после выбора количества.',
    VALIDATE_GEO: 'Проверяет страну найденных авторов. Авторы с подтверждённой страной ЮАР попадают в каталог.',
    DISCOVER_VIDEOS: 'Ищет новые публикации у авторов, которые уже есть в базе.',
    REFRESH_METRICS: 'Сохраняет новые значения просмотров, лайков, репостов и других доступных счётчиков. По этим замерам можно увидеть, как растёт видео.',
    EVALUATE_VIDEO: 'Сравнивает замеры просмотров и вовлечённости. ИИ оценивает рост видео и решает, где стоит разобрать комментарии.',
    COLLECT_COMMENTS: 'Берёт сохранённые комментарии к видео, которые ИИ выбрал для подробного анализа.',
};

export function CommandDialog({ command, devices, icon, close, action, actionLabel, disabledReason, needsSource, busy, notice, version, cancel, offline }: {
    command: CollectionCommand; icon: ReactNode; close: () => void; action: () => void; actionLabel: string;
    devices: Device[];
    disabledReason: string | null; needsSource: boolean; busy: boolean; offline: boolean;
    notice: { message: string; error: boolean } | null;
    version: number; cancel: (job: Job) => void;
}) {
    const dialog = useRef<HTMLDialogElement>(null);
    const title = useRef<HTMLHeadingElement>(null);
    const [jobs, setJobs] = useState<Job[] | null>(null);
    const [error, setError] = useState<string | null>(null);
    useEffect(() => {
        const element = dialog.current;
        const opener = document.activeElement;
        element?.showModal();
        title.current?.focus({ preventScroll: true });
        return () => {
            element?.close();
            if (opener instanceof HTMLElement && opener.isConnected) opener.focus({ preventScroll: true });
        };
    }, []);
    useEffect(() => {
        let active = true;
        let timer: number | undefined;
        let controller: AbortController | undefined;
        async function load() {
            const request = new AbortController();
            controller = request;
            const timeout = window.setTimeout(() => request.abort(), 16000);
            try {
                const snapshot = await api<{ jobs: Job[] }>(base + '/production?type=' + encodeURIComponent(command.type),
                    { signal: request.signal });
                if (active) {
                    setJobs(snapshot.jobs.filter((job) => job.type === command.type));
                    setError(null);
                }
            } catch (e) {
                if (active) setError(e instanceof Error && !request.signal.aborted ? e.message : 'Не удалось обновить журнал.');
            } finally {
                window.clearTimeout(timeout);
                if (active) timer = window.setTimeout(load, 6500);
            }
        }
        void load();
        return () => { active = false; controller?.abort(); window.clearTimeout(timer); };
    }, [command.type, version]);

    return <dialog ref={dialog} className="pf-command-dialog" aria-labelledby="pf-command-title"
        onCancel={(event) => { event.preventDefault(); close(); }}>
        <header className="pf-dialog-header">
            <span className="pf-action-icon">{icon}</span>
            <h2 id="pf-command-title" ref={title} tabIndex={-1}>{commandLabels[command.type]}</h2>
            <button type="button" className="pf-round-button" onClick={close} aria-label="Закрыть окно"><X size={18} /></button>
        </header>
        {needsSource ? <>
            <p className="pf-command-description">{command.reason || 'В Farm пока не подключён источник страны аккаунта TikTok.'}</p>
            {command.pending_targets != null && <dl className="pf-run-values"><div>
                <dt>Ожидают проверки</dt><dd>{displayCount(command.pending_targets)}</dd>
            </div></dl>}
        </> : <>
            <p className="pf-command-description">{command.source_mode === 'archive'
                ? 'Загружает сохранённые публикации из архива ленты ЮАР и проверяет, какие из них уже есть в базе.'
                : descriptions[command.type] || command.description}</p>
            {command.type !== 'VALIDATE_GEO' && <div className="pf-dialog-controls">
                <button type="button" className="pf-btn pf-primary" disabled={disabledReason !== null || busy} onClick={action}>
                    {busy && <LoaderCircle className="pf-spin" size={15} />}{actionLabel}
                </button>
            </div>}
            {disabledReason && <p className="pf-detail-reason">{disabledReason}</p>}
        </>}
        {command.type === 'VALIDATE_GEO' && <GeoPreview offline={offline} />}
        {notice && <p className={'pf-notice ' + (notice.error ? 'pf-error' : '')} role={notice.error ? 'alert' : 'status'}>{notice.message}</p>}
        <section className="pf-command-log" aria-label="Журнал запусков">
            <h3>Журнал запусков</h3>
            {error && <p className="pf-detail-reason" role="status">{error}</p>}
            {jobs?.length ? <RunList jobs={jobs} devices={devices} busy={busy || offline} cancel={cancel} /> :
                !error && <p className="pf-empty">{jobs === null ? 'Загружаем журнал…' : 'Запусков пока не было.'}</p>}
        </section>
    </dialog>;
}
