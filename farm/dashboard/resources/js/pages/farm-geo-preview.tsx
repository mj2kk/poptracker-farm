import { useEffect, useRef, useState } from 'react';
import { api, base, post, shortTime } from './farm-api';

type Author = { author_uid: string; username: string; video_id: string };
type Target = Author & { geo_observation?: { region: string | null; observed_ms: number } | null };
type Control = Author & { configured_country_code: string; reference_status: string };
type Report = { uid: string; vid: string; role: 'target' | 'control'; region: string | null };
type Task = { task_id: string; status: string; error_message: string | null; result: { observations: Report[] } | null };
type Preview = { prepared_at: string; targets: Target[]; controls: Control[]; scope_sha256: string;
    paused: boolean; ready_devices: {device_id: string; display_name: string}[]; last_task: Task | null };
const statuses: Record<string,string> = { queued: 'В очереди', leased: 'На телефоне', succeeded: 'Получен ответ',
    failed: 'Ошибка', cancelled: 'Отменено', expired: 'Время ожидания истекло' };

function ProfileLink({ author }: { author: Author }) {
    return <a href={`https://www.tiktok.com/@${encodeURIComponent(author.username)}/video/${encodeURIComponent(author.video_id)}`}
        target="_blank" rel="noopener noreferrer">@{author.username}</a>;
}

export function GeoPreview({ offline }: { offline: boolean }) {
    const [sample, setSample] = useState<Preview | null>(null);
    const [device, setDevice] = useState('');
    const [sent, setSent] = useState('');
    const intent = useRef<{ scope: string; device: string; id: string } | null>(null);
    const sending = useRef(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const active = useRef<AbortController | null>(null);
    useEffect(() => () => { active.current?.abort(); active.current = null; }, []);

    async function prepare() {
        if (offline || active.current || sending.current) return;
        const controller = new AbortController();
        active.current = controller;
        setBusy(true);
        setError('');
        const timer = window.setTimeout(() => controller.abort(), 17000);
        try {
            const data = await api<Preview>(base + '/production?type=VALIDATE_GEO&preview=geo', { signal: controller.signal });
            if (active.current === controller) {
                const readyDevices = data.ready_devices ?? [];
                setSample({ ...data, ready_devices: readyDevices });
                if (data.last_task?.task_id === intent.current?.id && data.last_task &&
                    !['queued', 'leased'].includes(data.last_task.status)) {
                    intent.current = null;
                    setSent('');
                }
                setDevice(previous => readyDevices.some(d => d.device_id === previous) ? previous : readyDevices[0]?.device_id ?? '');
            }
        } catch (e) {
            if (active.current === controller) setError(controller.signal.aborted ? 'Сервер не успел подготовить выборку.' :
                e instanceof Error ? e.message : 'Не удалось подготовить выборку.');
        } finally {
            window.clearTimeout(timer);
            if (active.current === controller) { active.current = null; setBusy(false); }
        }
    }

    async function submit() {
        if (!sample || !device || offline || busy || sending.current || sample.paused) return;
        sending.current = true;
        setBusy(true); setError('');
        try {
            if (intent.current?.scope !== sample.scope_sha256 || intent.current.device !== device) {
                intent.current = { scope: sample.scope_sha256, device, id: crypto.randomUUID() };
            }
            const result = await post<{task_id: string}>(base + '/production/jobs', {
                type: 'VALIDATE_GEO', device_id: device, scope_sha256: sample.scope_sha256, request_id: intent.current.id,
            });
            setSent(result.task_id);
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Не удалось передать задание.');
        } finally { sending.current = false; setBusy(false); }
    }

    const known = sample?.targets.filter(row => row.geo_observation?.region).length ?? 0;
    return <section className="pf-geo-preview" aria-labelledby="pf-geo-preview-title">
        <h3 id="pf-geo-preview-title">Выборка для замера на телефоне</h3>
        <p className="pf-command-description">Farm подберёт до 10 авторов из очереди и их ролики.
            Для проверки кода региона используются два контрольных аккаунта.</p>
        <button type="button" className="pf-btn" disabled={offline || busy} onClick={() => void prepare()}>
            {busy && !sending.current ? 'Подбираем авторов…' : sample ? 'Обновить выборку' : 'Подготовить выборку'}
        </button>
        {error && <p role="alert" className="pf-detail-reason">{error}</p>}
        {sample && <>
            <p role="status"><strong>Регион получен для {known} из {sample.targets.length} авторов.</strong> Данные на {shortTime(sample.prepared_at)}.</p>
            {!sample.ready_devices.length && <p className="pf-detail-reason">Запуск недоступен. Ни один телефон Farm не сообщил о доступе к User.region.</p>}
            {sample.targets.length > 0 && <div className="pf-geo-list">
                <table aria-label="Регионы выбранных авторов"><thead><tr><th scope="col">Автор</th><th scope="col">Регион аккаунта</th></tr></thead>
                    <tbody>{sample.targets.map(row => <tr key={row.author_uid}>
                        <th scope="row"><ProfileLink author={row} />
                            <details><summary>UID и VID</summary><div>UID {row.author_uid}</div><div>VID {row.video_id}</div></details>
                        </th>
                        <td>{row.geo_observation ? <>
                            <strong>{row.geo_observation.region ?? 'Поле пусто'}</strong>
                            <time dateTime={new Date(row.geo_observation.observed_ms).toISOString()}>
                                {new Date(row.geo_observation.observed_ms).toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'short' })}
                            </time>
                        </> : 'Нет результата'}</td>
                    </tr>)}</tbody></table>
            </div>}
            <h4>Контрольные аккаунты</h4>
            <ul>{sample.controls.map((row) => <li key={row.author_uid}>
                <ProfileLink author={row} /> · ожидается {row.configured_country_code}
                {row.reference_status !== 'historical_country_matches' && ' · требуется сверка с каталогом'}
            </li>)}</ul>
            {sample.ready_devices.length > 0 && <>
                <h4>Запуск через Android IPC</h4>
                <label>Телефон <select value={device} onChange={e => { setDevice(e.target.value); setSent(''); }}>
                    {sample.ready_devices.map(d => <option key={d.device_id} value={d.device_id}>{d.display_name}</option>)}
                </select></label>
                <button type="button" className="pf-btn pf-primary" onClick={() => void submit()}
                    disabled={offline || busy || sample.paused || !sample.scope_sha256 || !sample.targets.length || !device ||
                        (sample.last_task != null && ['queued','leased'].includes(sample.last_task.status)) ||
                        sample.controls.length !== 2 ||
                        sample.controls.some(c => c.reference_status !== 'historical_country_matches') ||
                        (sent !== '' && intent.current?.scope === sample.scope_sha256 && intent.current.device === device)}>
                    {sending.current ? 'Передаём задание…' : 'Передать выборку на телефон'}</button>
                {sample.paused && <p>Очередь Farm приостановлена.</p>}
            </>}
            {sent && <p role="status">Задание принято сервером. Обновите выборку, чтобы проверить результат.</p>}
            {sample.last_task && <div>
                <p>Последнее задание: {statuses[sample.last_task.status] || sample.last_task.status}.
                    {sample.last_task.error_message && ' ' + sample.last_task.error_message}</p>
                {sample.last_task.result && <ul>{sample.last_task.result.observations.filter(o => o.role === 'target')
                    .map(o => <li key={o.uid}>{o.uid} · {o.region ?? 'Регион отсутствовал в ответе'}</li>)}</ul>}
            </div>}
        </>}
    </section>;
}
