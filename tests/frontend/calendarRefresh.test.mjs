import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { effectScope, nextTick, reactive } from 'vue';
import ts from 'typescript';

const source = await readFile(new URL('../../resources/js/lib/calendarRefresh.ts', import.meta.url), 'utf8');
const compiled = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.ESNext } }).outputText
 .replace("from 'vue'", `from ${JSON.stringify(import.meta.resolve('vue'))}`);
const { watchCalendarRefresh } = await import(`data:text/javascript;base64,${Buffer.from(compiled).toString('base64')}`);

test('task and habit saves do not reload the calendar when Inertia replaces page data', async () => {
 const state = reactive({ data: { events: [{ id: 'event-1', title: 'Appointment' }], tasks: [{ done: false }], habits: [{ completed: false }] }, range: '2026-10', timezone: 'Asia/Makassar' });
 const scope = effectScope(); let loads = 0;
 scope.run(() => watchCalendarRefresh(() => state, () => { loads++; }));
 assert.equal(loads, 1);
 state.data = { events: [{ id: 'event-1', title: 'Appointment' }], tasks: [{ done: true }], habits: [{ completed: false }] };
 await nextTick();
 assert.equal(loads, 1);
 state.data = { events: [{ id: 'event-1', title: 'Appointment' }], tasks: [{ done: true }], habits: [{ completed: true }] };
 await nextTick();
 assert.equal(loads, 1);
 scope.stop();
});

test('event changes, future-event saves, month and timezone changes still refresh', async () => {
 const state = reactive({ data: { events: [{ id: 'event-1', title: 'Appointment' }] }, range: '2026-10', timezone: 'Asia/Makassar', revision: 0 });
 const scope = effectScope(); let loads = 0;
 scope.run(() => watchCalendarRefresh(() => state, () => { loads++; }));
 state.data.events[0].title = 'Updated appointment'; await nextTick(); assert.equal(loads, 2);
 state.revision++; await nextTick(); assert.equal(loads, 3);
 state.range = '2026-11'; await nextTick(); assert.equal(loads, 4);
 state.timezone = 'UTC'; await nextTick(); assert.equal(loads, 5);
 state.data.events = []; await nextTick(); assert.equal(loads, 6);
 scope.stop();
 state.revision++; await nextTick(); assert.equal(loads, 6);
});
