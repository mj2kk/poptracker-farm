import { CircleHelp } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import type { FeedPricing } from './farm-feed-dialog';
import type { Device } from './farm-api';

export const commandLabels: Record<string, string> = {
    IMPORT_FEED: 'Собрать ленту ЮАР',
    VALIDATE_GEO: 'Проверить страны авторов',
    DISCOVER_VIDEOS: 'Найти новые видео авторов',
    REFRESH_METRICS: 'Обновить статистику видео',
    EVALUATE_VIDEO: 'Оценить рост видео с ИИ',
    COLLECT_COMMENTS: 'Собрать комментарии',
};

export const states = {
    running: 'Выполняется', pending: 'В очереди', completed: 'Завершено',
    failed: 'Ошибка', blocked: 'Заблокировано', cancelled: 'Отменено',
} as const;

export type JobState = keyof typeof states;
export type Job = {
    id: string;
    type: string;
    target: string | null;
    priority: number;
    status: JobState;
    device_id: string | null;
    created_at: string;
    started_at: string | null;
    finished_at: string | null;
    next_run_at: string | null;
    attempts: number;
    result: string | null;
    error: string | null;
    cancellable?: boolean;
    cursor_position?: number;
    total?: number | null;
    written?: number;
    rejected?: number;
    feed?: { requested_count: number; new_candidates: number; duplicate_videos: number; cost_usd: string | null } | null;
    evaluation?: { status: string; attention_status: string | null; collect_comments: boolean; reason?: string | null } | null;
};

export type CollectionCommand = {
    type: string;
    label: string;
    source_mode?: 'apify' | 'archive';
    pricing?: FeedPricing;
    description: string;
    available: boolean;
    unavailable_code?: string | null;
    reason: string | null;
    pending_targets: number | null;
    last_run_at: string | null;
    last_result: string | null;
    progress: { done: number; total: number | null } | null;
};

export const displayCount = (value: number) => value.toLocaleString('ru-RU');
const dates = new Intl.DateTimeFormat('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
const dollars = new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'USD', minimumFractionDigits: 2, maximumFractionDigits: 8 });

function dateTime(value: string) {
    const date = new Date(value.includes('T') ? value : value.replace(' ', 'T') + 'Z');
    return Number.isFinite(date.valueOf()) ? dates.format(date) : value;
}

function readableResult(raw: string) {
    return raw.replace(/\$(\d+(?:\.\d+)?)/g, (_, amount: string) => dollars.format(Number(amount)));
}

export function Help({ label, text }: { label: string; text: string }) {
    const id = useId();
    const wrapper = useRef<HTMLSpanElement>(null);
    const [open, setOpen] = useState(false);
    const [pinned, setPinned] = useState(false);
    useEffect(() => {
        if (!open) return;
        function dismiss(event: Event) {
            if (event.type === 'keydown' && (event as KeyboardEvent).key !== 'Escape') return;
            if (event.type === 'pointerdown' && wrapper.current?.contains(event.target as Node)) return;
            setOpen(false);
            setPinned(false);
        }
        document.addEventListener('keydown', dismiss);
        document.addEventListener('pointerdown', dismiss);
        return () => {
            document.removeEventListener('keydown', dismiss);
            document.removeEventListener('pointerdown', dismiss);
        };
    }, [open]);

    return <span ref={wrapper} className="pf-help"
        onPointerEnter={(event) => { if (event.pointerType === 'mouse') setOpen(true); }}
        onPointerLeave={() => { if (!pinned && !wrapper.current?.querySelector(':focus-visible')) setOpen(false); }}>
        <button type="button" className="pf-help-trigger" aria-label={'Подробнее: ' + label}
            aria-describedby={open ? id : undefined} aria-expanded={open}
            onFocus={(e) => { if (e.currentTarget.matches(':focus-visible')) setOpen(true); }}
            onBlur={() => { setOpen(false); setPinned(false); }}
            onClick={() => { setPinned(!pinned); setOpen(!pinned); }}>
            <CircleHelp size={15} aria-hidden="true" />
        </button>
        {open && <span className="pf-help-text" id={id} role="tooltip">{text}</span>}
    </span>;
}

function JobResult({ job }: { job: Job }) {
    const feed = job.feed;
    if (job.type === 'IMPORT_FEED' && job.status === 'completed' && feed && job.cursor_position != null && job.written != null) {
        const facts: [string, string][] = [
            ['Запрошено публикаций', displayCount(feed.requested_count)],
            ['Получено публикаций', displayCount(job.cursor_position)],
            ['Новые видео', displayCount(job.written)],
            ['Уже были в базе', displayCount(feed.duplicate_videos)],
            ['Новые авторы', displayCount(feed.new_candidates)],
        ];
        if (job.rejected) facts.push(['Отклонено строк', displayCount(job.rejected)]);
        if (feed.cost_usd != null && Number.isFinite(Number(feed.cost_usd))) facts.push(['Стоимость', dollars.format(Number(feed.cost_usd))]);
        return <dl className="pf-run-values">{facts.map(([label, value]) => <div key={label}><dt>{label}</dt><dd>{value}</dd></div>)}</dl>;
    }
    const evaluation = job.evaluation;
    if (job.status === 'completed' && evaluation) {
        const priorities: Record<string, string> = { low: 'Низкий приоритет', medium: 'Средний приоритет', high: 'Высокий приоритет' };
        const verdict = evaluation.status === 'insufficient_data' ? 'Недостаточно данных для оценки' : priorities[evaluation.attention_status ?? ''] ?? 'Оценка завершена';
        return <div className="pf-run-result"><strong>{verdict}</strong>
            {evaluation.reason && <p>{evaluation.reason}</p>}
            {evaluation.collect_comments && <p>Выбрано для разбора комментариев.</p>}</div>;
    }
    const result = job.error || job.result;
    return result ? <p className="pf-run-result">{readableResult(result)}</p> : null;
}

export function RunList({ jobs, devices, global = false, busy, cancel }: {
    jobs: Job[]; devices: Device[]; global?: boolean; busy: boolean; cancel: (job: Job) => void;
}) {
    const [limit, setLimit] = useState(10);
    return <>
        <ol className="pf-run-list">{jobs.slice(0, limit).map((job) => <li className="pf-run" key={job.id}>
            {global && <strong className="pf-run-name">{commandLabels[job.type] ?? job.type}</strong>}
            <div className="pf-run-header">
                <time dateTime={(job.started_at || job.created_at).replace(' ', 'T')}>{dateTime(job.started_at || job.created_at)}</time>
                <span className={'pf-task-state s-' + job.status}>{states[job.status]}</span>
            </div>
            {job.type !== 'IMPORT_FEED' && job.target && <p className="pf-run-target">{(job.type === 'EVALUATE_VIDEO' || job.type === 'COLLECT_COMMENTS' ? 'ID видео · ' : 'Цель · ') + job.target}</p>}
            {job.device_id && <p className="pf-run-target">Телефон последней партии · {devices.find((device) => device.device_id === job.device_id)?.display_name ?? 'Android'}</p>}
            {job.total != null && job.cursor_position != null && (job.status === 'running' || job.status === 'pending') &&
                <p className="pf-run-progress">Обработано {displayCount(job.cursor_position)} из {displayCount(job.total)}</p>}
            <JobResult job={job} />
            {(job.cancellable ?? ['pending','blocked'].includes(job.status)) && <button type="button"
                className="pf-cancel-job" disabled={busy} onClick={() => cancel(job)}>Отменить задание</button>}
        </li>)}</ol>
        {jobs.length > limit && <button type="button" className="pf-more" onClick={() => setLimit(limit + 10)}>Показать ещё</button>}
    </>;
}
