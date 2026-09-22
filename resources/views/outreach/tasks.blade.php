<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Задачі · розкрутка Dovira</title>
    <style>
        :root {
            --bg:#f4f5f8; --panel:#fff;
            --ink:#14161c; --ink-2:#565d6b; --ink-3:#949aa6;
            --line:#e9eaef; --line-2:#f1f2f6; --inset:#f7f8fb;
            --accent:#2f6bff; --accent-600:#1f57e6; --accent-soft:#ecf1ff;
            --ok:#0a8f4f; --ok-soft:#e7f6ee;
            --r:18px; --r-sm:12px;
            --sh:0 1px 2px rgba(20,22,28,.04), 0 4px 16px rgba(20,22,28,.04);
        }
        * { box-sizing: border-box; }
        html, body { margin: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: var(--bg); color: var(--ink); font-size: 14px; line-height: 1.5; -webkit-font-smoothing: antialiased; }
        a { color: var(--accent); text-decoration: none; }
        ::selection { background: var(--accent-soft); }

        /* ── Topbar ── */
        .topbar { display: flex; align-items: center; justify-content: space-between; gap: 20px; flex-wrap: wrap;
            padding: 15px 26px; background: rgba(244,245,248,.85); backdrop-filter: saturate(1.6) blur(12px);
            border-bottom: 1px solid var(--line); position: sticky; top: 0; z-index: 20; }
        .topbar__title { display: flex; align-items: center; gap: 12px; }
        .topbar__title b { font-size: 16px; font-weight: 700; letter-spacing: -.01em; }
        .topbar__title span { color: var(--ink-3); font-size: 12.5px; }
        .topbar__right { display: flex; align-items: center; gap: 18px; flex-wrap: wrap; }
        .navlink { color: var(--ink-2); font-size: 13px; font-weight: 600; }
        .navlink:hover { color: var(--accent); }

        /* Overall progress in topbar */
        .headprog { display: flex; align-items: center; gap: 12px; }
        .headprog__ring { --p:0; width: 40px; height: 40px; border-radius: 50%; flex: none;
            background: conic-gradient(var(--ok) calc(var(--p)*1%), var(--line) 0);
            display: grid; place-items: center; transition: background .3s ease; }
        .headprog__ring i { width: 30px; height: 30px; border-radius: 50%; background: var(--panel);
            display: grid; place-items: center; font-size: 11px; font-weight: 800; color: var(--ink); font-style: normal;
            font-variant-numeric: tabular-nums; }
        .headprog__meta b { font-size: 14px; font-weight: 700; font-variant-numeric: tabular-nums; }
        .headprog__meta span { display: block; color: var(--ink-3); font-size: 12px; }

        .wrap { max-width: 980px; margin: 0 auto; padding: 26px 20px 80px; }
        .intro { color: var(--ink-2); font-size: 13.5px; margin: 0 0 22px; max-width: 62ch; }

        /* ── Group card ── */
        .group { background: var(--panel); border: 1px solid var(--line); border-radius: var(--r);
            box-shadow: var(--sh); padding: 18px 18px 8px; margin-bottom: 16px; }
        .group__head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 12px; }
        .group__title { font-size: 15px; font-weight: 700; letter-spacing: -.01em; margin: 0 0 2px; }
        .group__hint { color: var(--ink-3); font-size: 12.5px; margin: 0; }
        .group__count { flex: none; font-size: 12px; font-weight: 700; color: var(--ink-2);
            font-variant-numeric: tabular-nums; padding-top: 2px; }
        .bar { height: 5px; border-radius: 99px; background: var(--line); overflow: hidden; margin: 0 0 6px; }
        .bar__fill { height: 100%; width: 0; border-radius: 99px; background: var(--ok); transition: width .3s ease; }

        /* ── Two-column board: Термінові + Довіра ── */
        .board2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 22px; }
        .board2 .group { margin-bottom: 0; }
        .group--urgent .group__title { color: #c8323a; }
        .group--dovira .group__title { color: var(--accent-600); }
        @media (max-width: 720px) { .board2 { grid-template-columns: 1fr; } }

        /* ── Task row ── */
        .task { display: flex; align-items: flex-start; gap: 11px; padding: 11px 6px; border-top: 1px solid var(--line-2); }
        .task:first-of-type { border-top: 0; }
        .task input[type=checkbox] { appearance: none; -webkit-appearance: none; margin: 1px 0 0; flex: none;
            width: 20px; height: 20px; border: 1.6px solid #cfd4de; border-radius: 6px; background: #fff; cursor: pointer;
            position: relative; transition: background .15s ease, border-color .15s ease; }
        .task input[type=checkbox]:hover { border-color: var(--accent); }
        .task input[type=checkbox]:checked { background: var(--ok); border-color: var(--ok); }
        .task input[type=checkbox]:checked::after { content: ""; position: absolute; left: 6px; top: 2px;
            width: 5px; height: 10px; border: solid #fff; border-width: 0 2px 2px 0; transform: rotate(45deg); }
        .task__body { flex: 1; min-width: 0; }
        .task__text { cursor: pointer; display: inline; transition: color .15s ease; }
        .task.is-done .task__text { color: var(--ink-3); text-decoration: line-through; }
        .task__hint { display: block; color: var(--ink-3); font-size: 12px; line-height: 1.45; margin-top: 3px; max-width: 78ch; }
        .chip { display: inline-flex; align-items: center; gap: 5px; margin-left: 8px; padding: 1px 9px;
            border-radius: 99px; background: var(--accent-soft); color: var(--accent-600); font-size: 11.5px;
            font-weight: 600; text-decoration: none; vertical-align: 1px; }
        .chip:hover { background: #dfe8ff; }
        /* Row tools: color dots + delete. Приховані, зʼявляються на hover
           (або якщо задача вже позначена кольором). */
        .task__tools { flex: none; display: inline-flex; align-items: center; gap: 5px; padding-left: 6px;
            opacity: 0; transition: opacity .15s ease; }
        .task:hover .task__tools, .task.has-color .task__tools { opacity: 1; }
        .dot { width: 15px; height: 15px; border-radius: 50%; border: 1.5px solid rgba(20,22,28,.14);
            background: var(--c, #fff); cursor: pointer; padding: 0; display: inline-grid; place-items: center;
            font-size: 10px; line-height: 1; color: #fff; }
        .dot:hover { transform: scale(1.15); }
        .dot--clear { background: #fff; color: var(--ink-3); font-weight: 700; }
        .task__del { flex: none; border: 0; background: transparent; color: var(--ink-3); cursor: pointer;
            font-size: 16px; line-height: 1; padding: 0 2px 0 4px; transition: color .15s ease; }
        .task__del:hover { color: #d64545; }
        /* Позначка кольором: ліва смужка + легкий фон. */
        .task.has-color { box-shadow: inset 4px 0 0 var(--tc); border-radius: 8px; padding-left: 11px;
            background: color-mix(in srgb, var(--tc) 8%, transparent); }

        /* Add custom task */
        .addrow { display: flex; gap: 8px; padding: 10px 6px 12px; border-top: 1px dashed var(--line); margin-top: 2px; }
        .addrow input[type=text] { flex: 1; min-width: 0; border: 1px solid var(--line); border-radius: 10px;
            padding: 8px 12px; font: inherit; background: var(--inset); color: var(--ink); }
        .addrow input[type=text]:focus { outline: none; border-color: var(--accent); background: #fff; }
        .addrow button { flex: none; border: 0; border-radius: 10px; background: var(--accent); color: #fff;
            font: inherit; font-weight: 600; padding: 0 16px; cursor: pointer; transition: background .15s ease; }
        .addrow button:hover { background: var(--accent-600); }

        .btn-mini { border: 1px solid var(--line); background: #fff; color: var(--ink-2); border-radius: 9px;
            padding: 6px 12px; font: inherit; font-size: 12.5px; font-weight: 600; cursor: pointer; transition: all .15s ease; }
        .btn-mini:hover { border-color: #d64545; color: #d64545; }
        .group__reset { border: 1px solid var(--line); background: #fff; color: var(--ink-3); border-radius: 8px;
            padding: 4px 10px; font: inherit; font-size: 12px; font-weight: 600; cursor: pointer; }
        .group__reset:hover { color: var(--accent); border-color: var(--accent); }

        .saved { color: var(--ink-3); font-size: 12px; }
        @media (max-width: 560px) {
            .topbar { padding: 12px 16px; }
            .wrap { padding: 20px 14px 70px; }
            .group { padding: 15px 14px 6px; }
        }
    </style>
</head>
<body>
    <div class="topbar">
        <div class="topbar__title">
            <b>Задачі — розкрутка Dovira</b>
            <span>особистий трекер</span>
        </div>
        <div class="topbar__right">
            <div class="headprog">
                <div class="headprog__ring" data-overall-ring><i data-overall-pct>0%</i></div>
                <div class="headprog__meta">
                    <b><span data-overall-done>0</span>/<span data-overall-total>0</span></b>
                    <span>зроблено</span>
                </div>
            </div>
            <a class="navlink" href="{{ route('outreach') }}">Розсилка</a>
            <a class="navlink" href="{{ route('outreach.stats') }}">Воронка</a>
            <button type="button" class="btn-mini" data-reset-all>Скинути все</button>
        </div>
    </div>

    <div class="wrap" data-tasks-root>
        <p class="intro">
            Особистий трекер розкрутки Dovira. Угорі — два робочі вікна: «Термінові» й «Довіра».
            Нижче — готовий чеклист по каналах. Будь-яку задачу можна позначити кольором (навести → крапки),
            видалити (×) чи додати свою. Усе зберігається в цьому браузері.
        </p>

        {{-- Два вікна в ряд: власні термінові задачі + задачі по Dovira.
             Це звичайні групи (data-group), тому працюють з тим самим JS:
             додавання, кольорові мітки, видалення, прогрес. --}}
        <div class="board2">
            <section class="group group--urgent" data-group="urgent">
                <div class="group__head">
                    <div>
                        <h2 class="group__title">🔴 Термінові задачі</h2>
                        <p class="group__hint">Що горить прямо зараз.</p>
                    </div>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <span class="group__count" data-group-count>0/0</span>
                        <button type="button" class="group__reset" data-group-reset title="Зняти прапорці групи">↺</button>
                    </div>
                </div>
                <div class="bar"><div class="bar__fill" data-group-bar></div></div>
                <div data-group-list></div>
                <div class="addrow">
                    <input type="text" data-add-input placeholder="Нова термінова задача…" maxlength="160">
                    <button type="button" data-add-btn>Додати</button>
                </div>
            </section>

            <section class="group group--dovira" data-group="dovira">
                <div class="group__head">
                    <div>
                        <h2 class="group__title">🛡️ Задачі Довіра</h2>
                        <p class="group__hint">Робота над платформою.</p>
                    </div>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <span class="group__count" data-group-count>0/0</span>
                        <button type="button" class="group__reset" data-group-reset title="Зняти прапорці групи">↺</button>
                    </div>
                </div>
                <div class="bar"><div class="bar__fill" data-group-bar></div></div>
                <div data-group-list></div>
                <div class="addrow">
                    <input type="text" data-add-input placeholder="Нова задача по Dovira…" maxlength="160">
                    <button type="button" data-add-btn>Додати</button>
                </div>
            </section>
        </div>

        @foreach ($groups as $group)
            <section class="group" data-group="{{ $group['key'] }}">
                <div class="group__head">
                    <div>
                        <h2 class="group__title">{{ $group['title'] }}</h2>
                        @if (!empty($group['hint']))
                            <p class="group__hint">{{ $group['hint'] }}</p>
                        @endif
                    </div>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <span class="group__count" data-group-count>0/0</span>
                        <button type="button" class="group__reset" data-group-reset title="Зняти прапорці групи">↺</button>
                    </div>
                </div>
                <div class="bar"><div class="bar__fill" data-group-bar></div></div>

                <div data-group-list>
                    @foreach ($group['tasks'] as $task)
                        <label class="task" data-task-id="{{ $task['id'] }}">
                            <input type="checkbox">
                            <span class="task__body">
                                <span class="task__text">{{ $task['text'] }}</span>
                                @if (!empty($task['link']))
                                    <a class="chip" href="{{ $task['link']['href'] }}" target="_blank" rel="noopener">{{ $task['link']['label'] }} ↗</a>
                                @endif
                                @if (!empty($task['hint']))
                                    <span class="task__hint">{{ $task['hint'] }}</span>
                                @endif
                            </span>
                        </label>
                    @endforeach
                </div>

                <div class="addrow">
                    <input type="text" data-add-input placeholder="Додати свою задачу…" maxlength="160">
                    <button type="button" data-add-btn>Додати</button>
                </div>
            </section>
        @endforeach

        <p class="saved" data-saved-note></p>
    </div>

    <script>
    (() => {
        const STORAGE_KEY = 'dovira_growth_tasks_v1';
        const root = document.querySelector('[data-tasks-root]');
        if (!root) return;

        const load = () => {
            try { return JSON.parse(localStorage.getItem(STORAGE_KEY)) || {}; }
            catch (e) { return {}; }
        };
        const COLORS = ['#e5484d', '#f5a623', '#0a8f4f', '#2f6bff', '#8b5cf6'];
        const state = Object.assign({ checked: {}, custom: {}, colors: {}, deleted: {} }, load());
        state.checked = state.checked || {};
        state.custom = state.custom || {};
        state.colors = state.colors || {};
        state.deleted = state.deleted || {};

        let saveTimer = 0;
        const note = document.querySelector('[data-saved-note]');
        const save = () => {
            try { localStorage.setItem(STORAGE_KEY, JSON.stringify(state)); } catch (e) {}
            if (note) {
                note.textContent = 'Збережено ✓';
                window.clearTimeout(saveTimer);
                saveTimer = window.setTimeout(() => { note.textContent = ''; }, 1400);
            }
        };

        const escapeText = (s) => {
            const d = document.createElement('div');
            d.textContent = s;
            return d.innerHTML;
        };

        const applyColor = (label, id) => {
            const c = state.colors[id];
            if (c) { label.classList.add('has-color'); label.style.setProperty('--tc', c); }
            else { label.classList.remove('has-color'); label.style.removeProperty('--tc'); }
        };

        // Панель інструментів рядка: кольорові крапки + видалення. Чіпляємо до
        // будь-якої задачі — і стандартної, і власної. Видалення стандартної
        // памʼятаємо в state.deleted, щоб вона не поверталась.
        const decorate = (label, id, groupKey, isDefault) => {
            wireCheckbox(label, id);
            applyColor(label, id);

            const tools = document.createElement('span');
            tools.className = 'task__tools';

            COLORS.forEach((c) => {
                const dot = document.createElement('button');
                dot.type = 'button';
                dot.className = 'dot';
                dot.style.setProperty('--c', c);
                dot.title = 'Позначити кольором';
                dot.addEventListener('click', (e) => {
                    e.preventDefault(); e.stopPropagation();
                    if (state.colors[id] === c) delete state.colors[id]; else state.colors[id] = c;
                    applyColor(label, id);
                    save();
                });
                tools.appendChild(dot);
            });

            const clear = document.createElement('button');
            clear.type = 'button';
            clear.className = 'dot dot--clear';
            clear.title = 'Без кольору';
            clear.textContent = '×';
            clear.addEventListener('click', (e) => {
                e.preventDefault(); e.stopPropagation();
                delete state.colors[id];
                applyColor(label, id);
                save();
            });
            tools.appendChild(clear);

            const del = document.createElement('button');
            del.type = 'button';
            del.className = 'task__del';
            del.title = 'Видалити задачу';
            del.textContent = '×';
            del.addEventListener('click', (e) => {
                e.preventDefault(); e.stopPropagation();
                delete state.checked[id];
                delete state.colors[id];
                if (isDefault) {
                    state.deleted[id] = true;
                } else {
                    state.custom[groupKey] = (state.custom[groupKey] || []).filter((t) => t.id !== id);
                }
                label.remove();
                save();
                refreshGroup(groupKey);
            });
            tools.appendChild(del);

            label.appendChild(tools);
        };

        // Build a task row DOM node (used for custom tasks).
        const buildCustomRow = (groupKey, id, text) => {
            const label = document.createElement('label');
            label.className = 'task';
            label.dataset.taskId = id;
            label.innerHTML =
                '<input type="checkbox">' +
                '<span class="task__body"><span class="task__text">' + escapeText(text) + '</span></span>';
            decorate(label, id, groupKey, false);
            return label;
        };

        const wireCheckbox = (label, id) => {
            const box = label.querySelector('input[type=checkbox]');
            box.checked = !!state.checked[id];
            label.classList.toggle('is-done', box.checked);
            box.addEventListener('change', () => {
                if (box.checked) state.checked[id] = true; else delete state.checked[id];
                label.classList.toggle('is-done', box.checked);
                save();
                refreshGroup(label.closest('[data-group]').dataset.group);
            });
        };

        const groups = Array.from(document.querySelectorAll('[data-group]'));

        const refreshGroup = (groupKey) => {
            const group = groups.find((g) => g.dataset.group === groupKey);
            if (!group) return;
            const rows = group.querySelectorAll('[data-task-id]');
            const total = rows.length;
            let done = 0;
            rows.forEach((r) => { if (r.querySelector('input').checked) done++; });
            const pct = total ? Math.round((done / total) * 100) : 0;
            group.querySelector('[data-group-count]').textContent = done + '/' + total;
            group.querySelector('[data-group-bar]').style.width = pct + '%';
            refreshOverall();
        };

        const refreshOverall = () => {
            const all = document.querySelectorAll('[data-task-id]');
            const total = all.length;
            let done = 0;
            all.forEach((r) => { if (r.querySelector('input').checked) done++; });
            const pct = total ? Math.round((done / total) * 100) : 0;
            document.querySelector('[data-overall-done]').textContent = done;
            document.querySelector('[data-overall-total]').textContent = total;
            document.querySelector('[data-overall-pct]').textContent = pct + '%';
            document.querySelector('[data-overall-ring]').style.setProperty('--p', pct);
        };

        // Hydrate default tasks + restore custom tasks per group.
        groups.forEach((group) => {
            const groupKey = group.dataset.group;
            const list = group.querySelector('[data-group-list]');

            group.querySelectorAll('[data-group-list] .task').forEach((label) => {
                const id = label.dataset.taskId;
                if (state.deleted[id]) { label.remove(); return; }
                decorate(label, id, groupKey, true);
            });

            (state.custom[groupKey] || []).forEach((t) => {
                list.appendChild(buildCustomRow(groupKey, t.id, t.text));
            });

            const input = group.querySelector('[data-add-input]');
            const addBtn = group.querySelector('[data-add-btn]');
            const addTask = () => {
                const text = input.value.trim();
                if (!text) return;
                const id = 'custom-' + groupKey + '-' + Date.now();
                state.custom[groupKey] = state.custom[groupKey] || [];
                state.custom[groupKey].push({ id, text });
                list.appendChild(buildCustomRow(groupKey, id, text));
                input.value = '';
                save();
                refreshGroup(groupKey);
            };
            addBtn.addEventListener('click', addTask);
            input.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); addTask(); } });

            group.querySelector('[data-group-reset]').addEventListener('click', () => {
                group.querySelectorAll('[data-task-id]').forEach((label) => {
                    const box = label.querySelector('input');
                    if (box.checked) { box.checked = false; label.classList.remove('is-done'); delete state.checked[label.dataset.taskId]; }
                });
                save();
                refreshGroup(groupKey);
            });

            refreshGroup(groupKey);
        });

        document.querySelector('[data-reset-all]').addEventListener('click', () => {
            if (!window.confirm('Зняти всі прапорці? Власні задачі й кольори залишаться.')) return;
            state.checked = {};
            document.querySelectorAll('[data-task-id]').forEach((label) => {
                label.querySelector('input').checked = false;
                label.classList.remove('is-done');
            });
            save();
            groups.forEach((g) => refreshGroup(g.dataset.group));
        });

        refreshOverall();
    })();
    </script>
</body>
</html>
