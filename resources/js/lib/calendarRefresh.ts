import { watch } from 'vue';

type CalendarInputs = {
 data: { events?: unknown[] };
 range: unknown;
 timezone?: string;
 revision?: number;
};

// Inertia replaces dashboard data after every save. Only calendar inputs
// should invalidate its independently fetched month, not task/habit updates.
export function watchCalendarRefresh(inputs: () => CalendarInputs, refresh: () => unknown) {
 return watch(() => {
  const value = inputs();
  return JSON.stringify([value.range, value.timezone, value.revision ?? 0, value.data.events ?? []]);
 }, () => { void refresh(); }, { immediate: true });
}
