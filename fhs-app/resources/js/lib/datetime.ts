/**
 * The timezone every date is shown in.
 *
 * Timestamps are stored in UTC, so without this each browser would render them
 * in its own local time and two people could disagree about which day a sale
 * happened on. The business operates in one place, so its clock is the one that
 * matters.
 */
export const BUSINESS_TIME_ZONE = 'Asia/Dhaka';

/** en-GB puts the day before the month, giving "Wed 29 Jul 2026". */
export const formatDate = new Intl.DateTimeFormat('en-GB', {
    weekday: 'short',
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    timeZone: BUSINESS_TIME_ZONE,
});

/** As above, with the time: "Wed 29 Jul 2026, 18:15". */
export const formatDateTime = new Intl.DateTimeFormat('en-GB', {
    weekday: 'short',
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    timeZone: BUSINESS_TIME_ZONE,
});

/**
 * A UTC timestamp in the `yyyy-mm-ddThh:mm` shape a datetime-local input needs,
 * expressed in business time.
 *
 * The input has no timezone of its own — it shows exactly the string given — so
 * the conversion has to happen here.
 */
export function toBusinessInputValue(iso: string | Date): string {
    const at = typeof iso === 'string' ? new Date(iso) : iso;

    // en-CA formats as yyyy-mm-dd, which is the shape the input wants.
    const parts = new Intl.DateTimeFormat('en-CA', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
        timeZone: BUSINESS_TIME_ZONE,
    }).formatToParts(at);

    const get = (type: Intl.DateTimeFormatPartTypes) => parts.find((part) => part.type === type)?.value ?? '';

    // Midnight comes back as "24" from some ICU versions.
    const hour = get('hour') === '24' ? '00' : get('hour');

    return `${get('year')}-${get('month')}-${get('day')}T${hour}:${get('minute')}`;
}

/** Now, in business time, for a datetime-local input. */
export function businessNow(): string {
    return toBusinessInputValue(new Date());
}

/**
 * Today in business time, as `yyyy-mm-dd` for a date input.
 *
 * Taken from the business clock rather than the browser's, so a machine set to
 * another timezone still agrees with the server about what day it is.
 */
export function businessToday(): string {
    return businessNow().slice(0, 10);
}

/**
 * Read a datetime-local value as business time and return it as UTC.
 *
 * The input yields a bare `yyyy-mm-ddThh:mm` with no zone attached, so it has
 * to be told which clock those digits belong to. Sent as-is, the server would
 * read them as UTC and store a sale six hours ahead of when it happened.
 *
 * Returns `yyyy-mm-dd hh:mm:ss`, the shape the database column stores.
 */
export function businessInputToUtc(value: string): string {
    if (value === '') {
        return value;
    }

    // Parsing as UTC first gives a fixed reference point; subtracting the
    // zone's offset at that moment then yields the true instant. Doing it this
    // way keeps the arithmetic correct across a DST change, where the offset
    // differs either side of the boundary.
    const asUtc = new Date(`${value}:00Z`);
    const offsetMs = offsetAt(asUtc);

    return new Date(asUtc.getTime() - offsetMs).toISOString().slice(0, 19).replace('T', ' ');
}

/** How far ahead of UTC the business timezone is at a given moment, in ms. */
function offsetAt(at: Date): number {
    // Formatting the same instant in both zones and differencing them gives the
    // offset without hardcoding +6, so a zone change needs no code change.
    const asBusiness = new Date(at.toLocaleString('en-US', { timeZone: BUSINESS_TIME_ZONE }));
    const asUtc = new Date(at.toLocaleString('en-US', { timeZone: 'UTC' }));

    return asBusiness.getTime() - asUtc.getTime();
}
