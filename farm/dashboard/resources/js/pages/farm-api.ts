export type Device = {
    device_id: string;
    display_name: string;
    manufacturer: string | null;
    model: string | null;
    android_version: string;
    agent_version: string;
    status: string;
    screen_on: boolean | null;
    battery_percent: number | null;
    last_seen_at: string | null;
    completed_tasks: number;
    current_task: { type: string } | null;
    last_error: { code: string; at: string } | null;
};
export const base = '/dashboard/phones';
export function shortTime(value: string | null, seconds = false) {
    if (!value) return '—';
    const date = new Date(value.includes('T') ? value : value.replace(' ', 'T') + 'Z');
    if (!Number.isFinite(date.valueOf())) return '—';
    return date.toLocaleTimeString('ru-RU', {
        hour: '2-digit',
        minute: '2-digit',
        ...(seconds ? { second: '2-digit' as const } : {}),
    });
}
function errorMessage(status: number) {
    if ([401, 419].includes(status)) return 'Сеанс истёк. Обновите страницу и войдите снова.';
    if (status === 403) return 'У этой учётной записи нет доступа к управлению.';
    if (status === 429) return 'Слишком много запросов. Повторите через минуту.';
    if (status === 409)
        return 'Состояние очереди изменилось. Проверьте последний запуск и доступность команды.';
    if (status === 422 || status === 404) return 'Проверьте выбранное устройство и параметры задания.';
    return 'Нет ответа от Farm. Проверьте соединение.';
}
export class FarmHttpError extends Error {
    constructor(public readonly status: number, message: string) {
        super(message);
    }
}
export async function api<T>(url: string, options: RequestInit = {}): Promise<T> {
    const res = await fetch(url, {
        ...options,
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'application/json', ...options.headers },
    });
    const json = res.headers.get('content-type')?.includes('application/json');
    if (res.status === 409 && json) {
        const problem = await res.json().catch(() => null);
        const detail =
            typeof problem?.message === 'string' && problem.message.length <= 500 ? problem.message : null;
        throw new FarmHttpError(409, detail || errorMessage(409));
    }
    if (!res.ok || !json) {
        const status = res.redirected ? 401 : res.status;
        throw new FarmHttpError(status, errorMessage(status));
    }
    return res.json();
}

export async function post<T>(url: string, body: object): Promise<T> {
    const csrf = document.cookie
        .split('; ')
        .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
        ?.slice(11);
    if (!csrf) throw new FarmHttpError(419, errorMessage(419));
    return api<T>(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(csrf) },
        body: JSON.stringify(body),
    });
}
