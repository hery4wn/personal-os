export type PreviewDay = { checked: boolean; events: number };

// Match the detailed calendar's inclusive, account-local event dates.
export function calendarPreview(events: any[], checked: string[], month: string, timezone: string): Record<string, PreviewDay> {
 const formatter = new Intl.DateTimeFormat('en-CA', { timeZone: timezone });
 const localDate = (value: string) => {
  const parts = formatter.formatToParts(new Date(value));
  return ['year', 'month', 'day'].map(type => parts.find(part => part.type === type)!.value).join('-');
 };
 const spans = events.map(event => ({ start: localDate(event.starts_at), end: localDate(event.ends_at || event.starts_at) }));
 const checkedDates = new Set(checked);
 const count = new Date(Date.UTC(Number(month.slice(0, 4)), Number(month.slice(5, 7)), 0)).getUTCDate();
 return Object.fromEntries(Array.from({ length: count }, (_, i) => {
  const date = `${month}-${String(i + 1).padStart(2, '0')}`;
  return [date, { checked: checkedDates.has(date), events: spans.filter(span => span.start <= date && span.end >= date).length }];
 }));
}
