import { ArrowRight, Radio, Wallet, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { CSSProperties, FormEvent } from 'react';

export type FeedPricing = {
    min_quantity: number;
    max_quantity: number;
    max_per_run: number;
    video_usd_micros: number;
    start_usd_micros: number;
};

export type FeedSelection = { requested_count: number; max_charge_cents: number };
export type FeedIntent = FeedSelection & { request_id: string };

const numbers = new Intl.NumberFormat('ru-RU');
const money = new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'USD' });
export const feedIntentKey = 'poptracker.apify.za.request.v1';
const uuid = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

export function readFeedIntent(): FeedIntent | null {
    try {
        const raw = sessionStorage.getItem(feedIntentKey);
        if (!raw) return null;
        // A request stored by the original 100-post action always had a ten-cent cap.
        if (uuid.test(raw)) return { request_id: raw, requested_count: 100, max_charge_cents: 10 };
        const value = JSON.parse(raw);
        if (value && typeof value.request_id === 'string' && uuid.test(value.request_id)
            && Number.isSafeInteger(value.requested_count) && value.requested_count >= 100 && value.requested_count <= 10000
            && Number.isSafeInteger(value.max_charge_cents) && value.max_charge_cents > 0) return value;
    } catch {
        // Missing or expired tab storage is checked again before a new submission.
    }
    return null;
}

function quote(pricing: FeedPricing, quantity: number): number {
    let cents = 0;
    for (let left = quantity; left > 0; left -= pricing.max_per_run) {
        const batch = Math.min(left, pricing.max_per_run);
        cents += Math.ceil((batch * pricing.video_usd_micros + pricing.start_usd_micros) / 10000);
    }
    return cents;
}

export function FeedDialog({ pricing, disabledReason, close, confirm }: {
    pricing: FeedPricing;
    disabledReason: string | null;
    close: () => void;
    confirm: (selection: FeedSelection) => void;
}) {
    const dialog = useRef<HTMLDialogElement>(null);
    const title = useRef<HTMLHeadingElement>(null);
    const opener = useRef<HTMLElement | null>(null);
    const [draft, setDraft] = useState(String(pricing.min_quantity));
    const [slider, setSlider] = useState(pricing.min_quantity);
    const quantity = Number(draft);
    const valid = /^\d+$/.test(draft) && Number.isSafeInteger(quantity)
        && quantity >= pricing.min_quantity && quantity <= pricing.max_quantity;
    const cap = valid ? quote(pricing, quantity) : null;

    useEffect(() => {
        const element = dialog.current;
        if (!opener.current && document.activeElement instanceof HTMLElement) opener.current = document.activeElement;
        element?.showModal();
        title.current?.focus({ preventScroll: true });
        return () => {
            element?.close();
            opener.current?.focus({ preventScroll: true });
        };
    }, []);

    function enter(value: string) {
        // Accept grouped numbers pasted from a Russian-language table or dashboard.
        const text = value.replace(/[ \u00a0\u202f]/g, '');
        setDraft(text);
        const next = Number(text);
        if (/^\d+$/.test(text) && next >= pricing.min_quantity && next <= pricing.max_quantity) setSlider(next);
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        if (cap === null || disabledReason) return;
        confirm({ requested_count: quantity, max_charge_cents: cap });
    }

    return (
        <dialog ref={dialog} className="pf-feed-dialog" aria-labelledby="pf-feed-title" aria-describedby="pf-feed-source"
            onCancel={(event) => { event.preventDefault(); close(); }}>
            <form onSubmit={submit} noValidate>
                <div className="pf-feed-titlebar">
                    <div className="pf-feed-symbol"><Radio size={23} aria-hidden="true" /></div>
                    <button type="button" className="pf-round-button" onClick={close} aria-label="Закрыть окно"><X size={19} /></button>
                </div>
                <h2 id="pf-feed-title" ref={title} tabIndex={-1}>Сбор ленты ЮАР<span>.</span></h2>
                <p id="pf-feed-source">TikTok Explore через Apify · последние 7 дней</p>
                <div className="pf-feed-control">
                    <label htmlFor="pf-feed-count">Количество публикаций</label>
                    <input id="pf-feed-count" className="pf-quantity" type="text" inputMode="numeric" autoComplete="off"
                        value={draft} onChange={(event) => enter(event.target.value)}
                        aria-invalid={!valid} aria-describedby={!valid ? 'pf-feed-error' : undefined} />
                </div>
                <input className="pf-range" type="range" aria-label="Количество публикаций — ползунок"
                    min={pricing.min_quantity} max={pricing.max_quantity} step={1} value={slider}
                    style={{ '--fill': `${100 * (slider - pricing.min_quantity) / (pricing.max_quantity - pricing.min_quantity)}%` } as CSSProperties}
                    onChange={(event) => enter(event.target.value)} />
                <div className="pf-range-limits" aria-hidden="true"><span>{numbers.format(pricing.min_quantity)}</span><span>{numbers.format(pricing.max_quantity)}</span></div>
                {!valid && <p className="pf-feed-validation" id="pf-feed-error">Введите целое число от {numbers.format(pricing.min_quantity)} до {numbers.format(pricing.max_quantity)}.</p>}
                <div className="pf-quote">
                    <div><Wallet size={18} aria-hidden="true" /><span>Максимальная стоимость</span></div>
                    <output htmlFor="pf-feed-count" aria-live="polite">{cap === null ? '—' : money.format(cap / 100)}</output>
                </div>
                <p className="pf-feed-note">Apify может вернуть меньше публикаций. Итоговая сумма зависит от числа запусков и полученных публикаций.</p>
                {disabledReason && <p role="status" className="pf-feed-validation">{disabledReason}</p>}
                <footer>
                    <button type="button" className="pf-btn" onClick={close}>Отмена</button>
                    <button type="submit" disabled={!valid || disabledReason !== null} className="pf-btn pf-primary">Запустить сбор <ArrowRight size={16} /></button>
                </footer>
            </form>
        </dialog>
    );
}
