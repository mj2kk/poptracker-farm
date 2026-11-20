import { Head, Link } from '@inertiajs/react';
import {
    Activity,
    BatteryMedium,
    Check,
    ChevronDown,
    ArrowUpLeft,
    CircleAlert,
    Clock3,
    Layers3,
    ListChecks,
    LoaderCircle,
    Monitor,
    Plus,
    RefreshCw,
    Smartphone,
    X,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { api, base, post, shortTime } from './farm-api';
import type { Device } from './farm-api';
import { CollectionPanel, commandLabels } from './farm-production';
import type { ProductionSnapshot } from './farm-production';
import { Spark } from '@/components/poptracker/Primitives';
import './farm-dashboard.css';

type Task = {
    task_id: string;
    device_id: string | null;
    type: string;
    status: string;
    created_at: string;
    error_code: string | null;
};
type Snapshot = { devices: Device[]; tasks: Task[]; sampled_at: string };
const types: Record<string, string> = {
    PING: 'Проверка связи',
    GET_DEVICE_STATUS: 'Статус устройства',
    EXECUTE_TEST_TASK: 'Тест выполнения',
    COLLECT_TEST_DATA: 'Сбор тестовых данных',
    UI_TEST_SCENARIO: 'Тест интерфейса Farm',
};
function taskName(type: string) {
    return type === 'ARCHIVE_COMMENTS_V1' ? commandLabels.COLLECT_COMMENTS : types[type] ?? type;
}
const taskStates: Record<string, string> = {
    queued: 'В очереди',
    leased: 'Выполняется',
    succeeded: 'Выполнено',
    failed: 'Ошибка',
    cancelled: 'Отменено',
};
const deviceErrors: Record<string, string> = {
    ACCESSIBILITY_DISABLED: 'Отключена служба теста',
    TARGET_PACKAGE_MISMATCH: 'Открыто другое приложение',
    TEST_SCREEN_NOT_OPEN: 'Тестовый экран закрыт',
    SCENARIO_TIMEOUT: 'Время теста истекло',
    STEP_TIMEOUT: 'Время шага истекло',
    LEASE_EXPIRED: 'Устройство не подтвердило результат вовремя',
    INVALID_PAYLOAD: 'Некорректные параметры задания',
    EXECUTION_UNKNOWN: 'Результат не подтверждён',
    UNSUPPORTED_TASK: 'Агент не поддерживает задание',
    NODE_NOT_FOUND: 'Элемент тестового экрана не найден',
};
const connection: Record<string, string> = {
    online: 'Онлайн',
    offline: 'Офлайн',
    registered: 'Зарегистрирован',
    revoked: 'Доступ отозван',
};
function DeviceCard({
    d,
    live,
    assign,
}: {
    d: Device;
    live: boolean;
    assign?: () => void;
}) {
    const online = live && d.status === 'online';
    const lit = online && d.screen_on === true;
    const display = !live
        ? 'Нет свежих данных'
        : !online
          ? 'Экран недоступен'
          : d.screen_on === null
            ? 'Состояние экрана неизвестно'
            : lit
              ? 'Экран включён'
              : 'Экран выключен';
    const taskLabel = d.current_task
        ? assign || d.current_task.type === 'ARCHIVE_COMMENTS_V1'
            ? taskName(d.current_task.type)
            : 'Служебное задание'
        : null;
    const maker = (d.manufacturer ?? d.display_name.split(' ')[0]).toLowerCase();
    const tone = maker.includes('xiaomi') ? 'apricot' : maker.includes('oneplus') ? 'blue' : 'green';
    return (
        <article className="pf-card" data-tone={tone} data-device={d.device_id}>
            <div className="pf-stage">
                <span className={'pf-status ' + (online ? 'is-online' : '')}>
                    <i />
                    {live ? (connection[d.status] ?? d.status) : 'Нет данных'}
                </span>
                <span className="pf-battery">
                    <BatteryMedium size={17} />
                    {d.battery_percent === null ? '—' : d.battery_percent + '%'}
                </span>
                <div className={'pf-phone ' + (lit ? 'is-lit' : '')} aria-hidden="true">
                    <span className="pf-glass">
                        <i className="pf-wall" />
                        <i className="pf-lens" />
                        {lit && <span className="pf-gesture" />}
                    </span>
                </div>
            </div>
            <div className="pf-card-body">
                <h2>{d.display_name}</h2>
                <p className="pf-spec">
                    Android {d.android_version}
                    <b>·</b>Farm {d.agent_version}
                </p>
                <div className={'pf-screen-state ' + (lit ? 'lit-text' : '')}>
                    <Monitor size={15} />
                    {display}
                </div>
                <dl className="pf-details">
                    <div>
                        <dt>Последняя связь</dt>
                        <dd>{shortTime(d.last_seen_at, true)}</dd>
                    </div>
                    <div>
                        <dt>Задание</dt>
                        <dd>{live ? (taskLabel ?? '—') : '—'}</dd>
                    </div>
                    {!assign && (
                        <>
                            <div>
                                <dt>Агент Farm</dt>
                                <dd>
                                    {!live
                                        ? '—'
                                        : !online
                                          ? 'Недоступен'
                                          : taskLabel
                                            ? 'Занят'
                                            : 'Свободен'}
                                </dd>
                            </div>
                            <div>
                                <dt>Последняя ошибка</dt>
                                <dd>
                                    {d.last_error
                                        ? (deviceErrors[d.last_error.code] ?? 'Ошибка выполнения') +
                                          ' · ' +
                                          shortTime(d.last_error.at, true)
                                        : '—'}
                                </dd>
                            </div>
                        </>
                    )}
                </dl>
                {assign && (
                    <button
                        className="pf-btn pf-card-action"
                        disabled={!live || d.status === 'revoked'}
                        onClick={assign}
                    >
                        <Plus size={16} />
                        Назначить задание
                    </button>
                )}
            </div>
        </article>
    );
}

function TaskDialog({
    devices,
    target,
    close,
    created,
}: {
    devices: Device[];
    target: string;
    close: () => void;
    created: (id: string) => void;
}) {
    const dialog = useRef<HTMLDialogElement>(null);
    const [who, setWho] = useState(target),
        [type, setType] = useState('PING'),
        [count, setCount] = useState('100'),
        [ms, setMs] = useState('1000');
    const [busy, setBusy] = useState(false),
        [error, setError] = useState('');
    const sending = useRef(false);
    useEffect(() => {
        const e = dialog.current;
        e?.showModal();
        return () => e?.close();
    }, []);
    async function submit(e: FormEvent) {
        e.preventDefault();
        if (sending.current) return;
        sending.current = true;
        setBusy(true);
        setError('');
        const payload =
            type === 'COLLECT_TEST_DATA'
                ? { dataset: 'synthetic_videos', record_count: Number(count) }
                : type === 'EXECUTE_TEST_TASK'
                  ? { duration_ms: Number(ms) }
                  : type === 'UI_TEST_SCENARIO'
                    ? { scenario: 'OWN_APP_BUTTON_AND_SCROLL_V1' }
                    : {};
        try {
            const r = await post<{ task_id: string }>(base + '/tasks', { target: who, type, payload });
            created(r.task_id);
        } catch (e) {
            setError(
                e instanceof Error ? e.message : 'Не удалось подтвердить создание. Проверьте журнал заданий.',
            );
        } finally {
            sending.current = false;
            setBusy(false);
        }
    }
    return (
        <dialog
            ref={dialog}
            className="pf-dialog"
            onCancel={(e) => {
                e.preventDefault();
                if (!busy) close();
            }}
            aria-labelledby="pf-dialog-title"
        >
            <form onSubmit={submit}>
                <div className="pf-dialog-head">
                    <h2 id="pf-dialog-title">Новое задание</h2>
                    <button
                        className="pf-icon"
                        type="button"
                        onClick={close}
                        disabled={busy}
                        aria-label="Закрыть"
                    >
                        <X size={20} />
                    </button>
                </div>
                <label>
                    Устройство
                    <select value={who} onChange={(e) => setWho(e.target.value)}>
                        <option value="any">Любое свободное устройство</option>
                        {devices
                            .filter((d) => d.status !== 'revoked')
                            .map((d) => (
                                <option key={d.device_id} value={d.device_id}>
                                    {d.display_name} · {connection[d.status]}
                                </option>
                            ))}
                    </select>
                </label>
                <label>
                    Задание
                    <select value={type} onChange={(e) => setType(e.target.value)}>
                        {Object.entries(types).map(([v, t]) => (
                            <option key={v} value={v}>
                                {t}
                            </option>
                        ))}
                    </select>
                </label>
                {type === 'COLLECT_TEST_DATA' && (
                    <label>
                        Количество записей (10–100)
                        <input
                            type="number"
                            required
                            min={10}
                            max={100}
                            value={count}
                            onChange={(e) => setCount(e.target.value)}
                        />
                    </label>
                )}
                {type === 'EXECUTE_TEST_TASK' && (
                    <label>
                        Длительность, мс (0–5000)
                        <input
                            type="number"
                            required
                            min={0}
                            max={5000}
                            value={ms}
                            onChange={(e) => setMs(e.target.value)}
                        />
                    </label>
                )}
                {type === 'UI_TEST_SCENARIO' && (
                    <p>
                        На телефоне должен быть открыт тестовый экран Farm и включена его служба специальных
                        возможностей.
                    </p>
                )}
                {error && (
                    <p role="alert" className="pf-error-text">
                        {error}
                    </p>
                )}
                <footer>
                    <button className="pf-btn" type="button" disabled={busy} onClick={close}>
                        Отмена
                    </button>
                    <button className="pf-btn pf-primary" disabled={busy}>
                        {busy ? <LoaderCircle className="pf-spin" size={16} /> : <Plus size={16} />}Поставить
                        в очередь
                    </button>
                </footer>
            </form>
        </dialog>
    );
}

type Mode = 'production' | 'development';

export default function FarmDashboard({ canDevelop = false }: { canDevelop?: boolean }) {
    const [mode, setMode] = useState<Mode>('production');
    const currentMode = canDevelop ? mode : 'production';
    return <FarmPanel key={currentMode} mode={currentMode} canDevelop={canDevelop} setMode={setMode} />;
}

function FarmPanel({
    mode,
    canDevelop,
    setMode,
}: {
    mode: Mode;
    canDevelop: boolean;
    setMode: (mode: Mode) => void;
}) {
    const development = mode === 'development';
    const [data, setData] = useState<Snapshot | ProductionSnapshot | null>(null);
    const [error, setError] = useState(''),
        [loading, setLoading] = useState(true);
    const [target, setTarget] = useState<string | null>(null),
        [message, setMessage] = useState('');
    const [all, setAll] = useState(false);
    const [commandFilter, setCommandFilter] = useState(''),
        [statusFilter, setStatusFilter] = useState('');
    const filters = new URLSearchParams();
    if (commandFilter) filters.set('type', commandFilter);
    if (statusFilter) filters.set('status', statusFilter);
    const query = filters.toString();
    const url = development ? base + '/snapshot' : base + '/production' + (query ? '?' + query : '');
    const pending = useRef<AbortController | null>(null);
    const activeUrl = useRef<string | null>(null);
    const refresh = useCallback(async () => {
        if (activeUrl.current !== url || pending.current) return;
        const c = new AbortController();
        pending.current = c;
        setLoading(true);
        const timer = window.setTimeout(() => c.abort(), 17000);
        try {
            const next = await api<Snapshot | ProductionSnapshot>(url, { signal: c.signal });
            if (pending.current === c) {
                setData(next);
                setError('');
            }
        } catch (e) {
            if (pending.current === c)
                setError(
                    e instanceof Error && e.name !== 'AbortError' ? e.message : 'Farm не ответила вовремя.',
                );
        } finally {
            clearTimeout(timer);
            if (pending.current === c) {
                pending.current = null;
                setLoading(false);
            }
        }
    }, [url]);
    useEffect(() => {
        activeUrl.current = url;
        void refresh();
        const tick = () => {
            if (!document.hidden) void refresh();
        };
        const id = window.setInterval(tick, 5000);
        document.addEventListener('visibilitychange', tick);
        return () => {
            activeUrl.current = null;
            clearInterval(id);
            document.removeEventListener('visibilitychange', tick);
            const c = pending.current;
            pending.current = null;
            c?.abort();
        };
    }, [refresh, url]);
    const production = data && 'overview' in data ? data : null;
    const devices = data?.devices ?? [],
        tasks = data && 'tasks' in data ? data.tasks : [],
        live = !!data && !error;
    const online = devices.filter((d) => d.status === 'online').length,
        lit = devices.filter((d) => d.status === 'online' && d.screen_on === true).length;
    const deviceSection = (
        <section className="pf-devices">
            <div className="pf-section-head">
                <h2>
                    <Smartphone size={18} />
                    Устройства <span>{data ? devices.length : '—'}</span>
                </h2>
                <div className="pf-summary">
                    {live ? (
                        <>
                            <i />
                            {online} онлайн <b>·</b> Экран включён у {lit}
                        </>
                    ) : error ? (
                        'Обновление приостановлено'
                    ) : (
                        'Получаем состояние…'
                    )}
                </div>
            </div>
            <section className="pf-grid" aria-label="Устройства">
                {devices.map((d) => (
                    <DeviceCard
                        key={d.device_id}
                        d={d}
                        live={live}
                        assign={development ? () => setTarget(d.device_id) : undefined}
                    />
                ))}
            </section>
            {!devices.length && (
                <p className="pf-empty">
                    {data
                        ? 'Зарегистрированных устройств пока нет.'
                        : error
                          ? 'Данные устройств недоступны.'
                          : 'Загружаем устройства…'}
                </p>
            )}
        </section>
    );
    return (
        <div className="pf-page">
            <Head
                title={development ? 'Разработка · PopTracker Farm' : 'Управление сбором · PopTracker Farm'}
            />
            <header className="pf-top">
                <div className="pf-top-inner">
                    <nav aria-label="Навигация">
                        <Link href="/poptracker" className="pf-brand" aria-label="PopTracker — коллекция ЮАР">
                            <Spark size={30} /><span>PopTracker<span>.</span></span>
                        </Link>
                        <span className="pf-sep" aria-hidden="true">/</span>
                        <strong>Farm</strong>
                    </nav>
                    <div className="pf-tools">
                        <Link href="/dashboard" className="pf-round-button" aria-label="Другие сервисы" title="Другие сервисы"><ArrowUpLeft size={19} /></Link>
                    <button
                        type="button"
                        onClick={() => void refresh()}
                        disabled={loading}
                        aria-label="Обновить данные"
                        className="pf-refresh"
                    >
                        <RefreshCw size={15} className={loading ? 'pf-spin' : ''} />
                        <span>Обновить</span>
                    </button>
                    </div>
                </div>
            </header>
            <main className="pf-main">
                <div className={'pf-heading' + (development ? ' pf-heading-dev' : '')}>
                    <div>
                        <h1>{development ? 'Управление телефонами' : 'Управление сбором'}<span>.</span></h1>
                        {development && <p>Устройства Android и очередь технических заданий.</p>}
                    </div>
                    {development && (
                        <button
                            className="pf-btn pf-primary"
                            disabled={!live || !devices.some((d) => d.status !== 'revoked')}
                            onClick={() => setTarget('any')}
                        >
                            <Plus size={17} />
                            Новое задание
                        </button>
                    )}
                </div>
                {canDevelop && (
                    <div className="pf-modes" role="group" aria-label="Режим интерфейса">
                        <button
                            type="button"
                            aria-pressed={!development}
                            onClick={() => setMode('production')}
                        >
                            Production
                        </button>
                        <button
                            type="button"
                            aria-pressed={development}
                            onClick={() => setMode('development')}
                        >
                            Development
                        </button>
                    </div>
                )}
                {error && (
                    <div role="alert" className="pf-notice pf-error">
                        <CircleAlert size={17} />
                        {error}
                    </div>
                )}
                {message && (
                    <div role="status" className="pf-notice">
                        <Check size={17} />
                        {message}
                        <button
                            className="pf-icon"
                            onClick={() => setMessage('')}
                            aria-label="Скрыть сообщение"
                        >
                            <X size={15} />
                        </button>
                    </div>
                )}
                {development ? (
                    <>
                        {deviceSection}
                        <section className="pf-ledger">
                            <div className="pf-ledger-head">
                                <h2>
                                    <ListChecks size={20} />
                                    Последние задания
                                </h2>
                                <span>
                                    <Clock3 size={14} />
                                    {data ? 'Обновлено в ' + shortTime(data.sampled_at, true) : '—'}
                                </span>
                            </div>
                            <div className="pf-scroll">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Задание</th>
                                            <th>Устройство</th>
                                            <th>Статус</th>
                                            <th>Создано</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {tasks.slice(0, all ? 20 : 6).map((t) => (
                                            <tr key={t.task_id}>
                                                <td title={t.task_id}>
                                                    <span className="pf-task-name">
                                                        <Layers3 size={15} />
                                                        {taskName(t.type)}
                                                    </span>
                                                </td>
                                                <td>
                                                    {devices.find((d) => d.device_id === t.device_id)
                                                        ?.display_name ??
                                                        (t.device_id ? 'Устройство' : 'Общая очередь')}
                                                </td>
                                                <td>
                                                    <span
                                                        title={t.error_code ?? undefined}
                                                        className={'pf-task-state s-' + t.status}
                                                    >
                                                        {t.status === 'succeeded' ? (
                                                            <Check size={13} />
                                                        ) : (
                                                            <Activity size={13} />
                                                        )}{' '}
                                                        {taskStates[t.status] ?? t.status}
                                                    </span>
                                                </td>
                                                <td title={t.created_at + ' UTC'}>
                                                    {shortTime(t.created_at, true)}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                            {!tasks.length && (
                                <p className="pf-empty">{data ? 'Заданий пока нет.' : 'Ожидание данных…'}</p>
                            )}
                            {tasks.length > 6 && (
                                <button className="pf-more" onClick={() => setAll(!all)}>
                                    {all ? 'Свернуть' : 'Показать ещё ' + (tasks.length - 6)}
                                    <ChevronDown
                                        size={15}
                                        style={{ transform: all ? 'rotate(180deg)' : undefined }}
                                    />
                                </button>
                            )}
                        </section>
                    </>
                ) : (
                    <CollectionPanel
                        data={production}
                        live={live}
                        refresh={refresh}
                        commandFilter={commandFilter}
                        setCommandFilter={setCommandFilter}
                        statusFilter={statusFilter}
                        setStatusFilter={setStatusFilter}
                    >
                        {deviceSection}
                    </CollectionPanel>
                )}
            </main>
            {development && target !== null && (
                <TaskDialog
                    target={target}
                    devices={devices}
                    close={() => setTarget(null)}
                    created={(id) => {
                        setTarget(null);
                        setMessage('Задание ' + id.slice(0, 8) + ' добавлено в очередь.');
                        void refresh();
                    }}
                />
            )}
        </div>
    );
}
