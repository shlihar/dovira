<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Розсилка · Dovira</title>
    <style>
        :root {
            --bg:#f4f5f8; --panel:#fff;
            --ink:#14161c; --ink-2:#565d6b; --ink-3:#949aa6;
            --line:#e9eaef; --line-2:#f1f2f6; --inset:#f7f8fb;
            --accent:#2f6bff; --accent-600:#1f57e6; --accent-soft:#ecf1ff;
            --ok:#0a8f4f; --ok-soft:#e7f6ee;
            --warn:#b7791f; --warn-soft:#fbf2df;
            --gold:#b8860b; --gold-soft:#faf2dd;
            --r:18px; --r-sm:12px;
            --sh:0 1px 2px rgba(20,22,28,.04), 0 4px 16px rgba(20,22,28,.04);
        }
        * { box-sizing: border-box; }
        html, body { margin: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: var(--bg); color: var(--ink); font-size: 14px; line-height: 1.5; -webkit-font-smoothing: antialiased; }
        a { color: var(--accent); }
        ::selection { background: var(--accent-soft); }

        /* ── Topbar ── */
        .topbar { display: flex; align-items: center; justify-content: space-between; gap: 20px; flex-wrap: wrap;
            padding: 15px 26px; background: rgba(244,245,248,.8); backdrop-filter: saturate(1.6) blur(12px);
            border-bottom: 1px solid var(--line); position: sticky; top: 0; z-index: 20; }
        .brand { display: flex; align-items: center; gap: 12px; }
        .brand .mark { width: 30px; height: 30px; border-radius: 9px; background: var(--ink); color: #fff;
            display: inline-flex; align-items: center; justify-content: center; font-weight: 700; font-size: 15px; }
        .brand h1 { font-size: 16px; margin: 0; font-weight: 650; letter-spacing: -.02em; }
        .brand .sub { color: var(--ink-3); font-size: 12px; margin-top: 1px; }
        .nav { display: flex; align-items: center; gap: 6px; }
        .pill { background: #fff; border: 1px solid var(--line); border-radius: 999px; padding: 6px 13px; font-size: 12px; color: var(--ink-2); }
        .pill b { color: var(--ink); font-weight: 600; }
        .navlink { text-decoration: none; font-size: 13px; font-weight: 550; padding: 8px 14px; border-radius: 10px; color: var(--ink-2); transition: .12s; }
        .navlink:hover { background: #fff; color: var(--ink); box-shadow: var(--sh); }
        .navlink.accent { color: var(--accent); }
        .navlink.accent:hover { background: var(--accent-soft); box-shadow: none; }

        /* ── Layout ── */
        .wrap { display: grid; grid-template-columns: 384px minmax(0, 1fr) 336px; gap: 20px; padding: 24px;
            align-items: start; max-width: 1560px; margin: 0 auto; }
        @media (max-width: 1200px) { .wrap { grid-template-columns: 1fr; } }

        .panel { background: var(--panel); border: 1px solid var(--line); border-radius: var(--r); box-shadow: var(--sh); }
        .panel + .panel { margin-top: 20px; }
        .pbody { padding: 18px; }
        .phead { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 16px 18px 0; }
        .phead h2 { font-size: 11px; margin: 0; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: var(--ink-3); }
        .count { color: var(--ink-3); font-size: 12px; font-variant-numeric: tabular-nums; }

        input[type=text], textarea { width: 100%; font: inherit; color: var(--ink); border: 1px solid var(--line);
            border-radius: var(--r-sm); padding: 11px 13px; background: #fff; outline: none; transition: border-color .12s, box-shadow .12s; }
        input[readonly] { background: var(--inset); color: var(--ink-2); }
        input[type=text]:focus, textarea:focus { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-soft); }
        textarea { resize: vertical; line-height: 1.65; }
        .hint { color: var(--ink-3); font-size: 12px; margin-top: 12px; line-height: 1.55; }
        code { background: var(--line-2); padding: 1px 6px; border-radius: 6px; font-size: 12px; color: var(--ink-2); }

        /* Пошук + фільтри */
        .search { position: relative; margin-top: 14px; }
        .search svg { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); width: 16px; height: 16px; color: var(--ink-3); pointer-events: none; }
        .search input { padding-left: 40px; border-radius: 999px; }
        .filters { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
        .fchip { display: inline-flex; align-items: center; gap: 7px; cursor: pointer; user-select: none; font-size: 12.5px; color: var(--ink-2);
            border: 1px solid var(--line); border-radius: 999px; padding: 6px 12px; transition: .12s; background: #fff; }
        .fchip:hover { border-color: var(--ink-3); }
        .fchip input { position: absolute; opacity: 0; pointer-events: none; }
        .fchip.on { background: var(--accent-soft); border-color: transparent; color: var(--accent-600); font-weight: 600; }
        .finfo { margin-left: auto; font-size: 11.5px; color: var(--ink-3); }

        /* Список */
        .list { max-height: 62vh; overflow-y: auto; margin-top: 10px; padding: 2px 8px 8px; scrollbar-width: thin; }
        .list::-webkit-scrollbar { width: 8px; }
        .list::-webkit-scrollbar-thumb { background: var(--line); border-radius: 8px; border: 2px solid var(--panel); }
        .row { display: flex; align-items: center; gap: 12px; width: 100%; text-align: left;
            border: 0; background: none; cursor: pointer; padding: 9px 11px; border-radius: 14px; color: inherit; font: inherit;
            transition: background .1s; position: relative; }
        .row:hover { background: var(--line-2); }
        .row.active { background: var(--accent-soft); }
        .row.sent { opacity: .5; }
        .row.sent:hover { opacity: .78; }

        .ava { flex: none; width: 36px; height: 36px; border-radius: 11px; display: inline-flex; align-items: center; justify-content: center;
            font-weight: 650; font-size: 15px; background: var(--c-bg, var(--line-2)); color: var(--c-fg, var(--ink-2)); position: relative; }
        .ava.dossier::after { content: ''; position: absolute; right: -2px; bottom: -2px; width: 11px; height: 11px; border-radius: 50%;
            background: var(--gold); border: 2px solid var(--panel); }
        .row.active .ava.dossier::after { border-color: var(--accent-soft); }

        .rmain { flex: 1; min-width: 0; display: flex; flex-direction: column; }
        .nm { display: block; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .rsub { display: block; color: var(--ink-2); font-size: 12px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; margin-top: 1px; }
        .rsub .rate { color: var(--gold); }
        .inact { color: var(--warn); }

        .rsig { flex: none; display: flex; align-items: center; gap: 4px; }
        .dot { width: 19px; height: 19px; border-radius: 7px; display: inline-flex; align-items: center; justify-content: center;
            font-size: 10.5px; font-weight: 700; line-height: 1; }
        .dot.d-link { background: var(--accent-soft); color: var(--accent-600); }
        .dot.d-acc { background: var(--ok-soft); color: var(--ok); }
        .dot.d-warn { background: var(--warn-soft); color: var(--warn); }
        .dot.d-paid { background: var(--gold-soft); color: var(--gold); }
        .dot.d-sent { background: var(--ok); color: #fff; border-radius: 50%; width: 18px; height: 18px; }

        .list-more { text-align: center; color: var(--ink-3); font-size: 12px; padding: 14px 0 6px; }
        .empty { color: var(--ink-3); text-align: center; padding: 30px 0; }

        .legend { display: flex; flex-wrap: wrap; gap: 8px 14px; padding: 14px 18px; border-top: 1px solid var(--line-2); font-size: 11.5px; color: var(--ink-2); }
        .legend span { display: inline-flex; align-items: center; gap: 6px; }

        /* Кнопки */
        .btn { display: inline-flex; align-items: center; gap: 8px; cursor: pointer; font: inherit; font-weight: 600;
            border-radius: var(--r-sm); padding: 11px 17px; border: 1px solid transparent; transition: .12s; }
        .btn-primary { background: var(--accent); color: #fff; box-shadow: 0 2px 8px rgba(47,107,255,.3); }
        .btn-primary:hover { background: var(--accent-600); }
        .btn-outline { background: #fff; color: var(--ink); border-color: var(--line); }
        .btn-outline:hover { border-color: var(--accent); color: var(--accent); }
        .btn-outline.is-sent { background: var(--ok-soft); border-color: transparent; color: var(--ok); }
        .btn[disabled] { opacity: .4; cursor: not-allowed; box-shadow: none; }
        .btn-ghost { background: none; color: var(--ink-3); border: 0; padding: 11px 8px; font-weight: 600; }
        .btn-ghost:hover { color: var(--accent); }

        /* Вкладки повідомлень */
        .tabs { display: flex; flex-wrap: wrap; gap: 4px; margin-bottom: 14px; padding: 4px; background: var(--inset); border-radius: 13px; }
        .tab { cursor: pointer; font: inherit; font-size: 13px; font-weight: 600; border: 0; background: transparent; color: var(--ink-2);
            border-radius: 10px; padding: 8px 14px; transition: .12s; }
        .tab:hover { color: var(--ink); }
        .tab.active { background: #fff; color: var(--accent); box-shadow: var(--sh); }

        .selname { display: inline-flex; align-items: center; gap: 7px; background: var(--accent-soft); color: var(--accent-600);
            font-weight: 600; font-size: 12px; padding: 5px 12px; border-radius: 999px; }
        .toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-top: 16px; }
        .toolbar .spacer { flex: 1; }
        .flag-dirty { color: var(--ink-3); font-size: 12px; }
        .linkrow { display: flex; gap: 8px; align-items: center; }
        .linkrow input { font-size: 12px; }

        /* Профіль-панель */
        .prof-head { display: flex; align-items: center; gap: 12px; }
        .prof-head .ava { width: 44px; height: 44px; border-radius: 13px; font-size: 18px; }
        .prof-head .nm { font-weight: 700; font-size: 15px; }
        .prof-head .city { color: var(--ink-3); font-size: 12.5px; margin-top: 1px; }
        .badges { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 14px; }
        .badge { font-size: 12px; font-weight: 600; border-radius: 8px; padding: 3px 10px; background: var(--line-2); color: var(--ink-2); }
        .badge.gold { background: var(--gold-soft); color: #916b0d; }
        .badge.ok { background: var(--ok-soft); color: var(--ok); }
        .warn-line { color: var(--warn); font-size: 12px; margin-top: 10px; background: var(--warn-soft); padding: 8px 11px; border-radius: 10px; }

        /* Статус-панель */
        .statgrid { margin-top: 14px; display: flex; flex-direction: column; gap: 8px; }
        .statrow { display: flex; align-items: center; gap: 11px; padding: 10px 12px; border-radius: 12px; background: var(--inset); }
        .statrow .ico { flex: none; width: 30px; height: 30px; border-radius: 9px; display: inline-flex; align-items: center; justify-content: center; font-size: 14px; background: #fff; color: var(--ink-3); box-shadow: inset 0 0 0 1px var(--line); }
        .statrow .txt { flex: 1; min-width: 0; }
        .statrow .k { font-size: 10.5px; color: var(--ink-3); text-transform: uppercase; letter-spacing: .04em; }
        .statrow .v { font-weight: 600; font-size: 13px; }
        .statrow.on-link { background: var(--accent-soft); } .statrow.on-link .ico { background: var(--accent); color: #fff; box-shadow: none; }
        .statrow.on-acc { background: var(--ok-soft); } .statrow.on-acc .ico { background: var(--ok); color: #fff; box-shadow: none; }
        .statrow.on-warn { background: var(--warn-soft); } .statrow.on-warn .ico { background: var(--warn); color: #fff; box-shadow: none; }
        .statrow.on-paid { background: var(--gold-soft); } .statrow.on-paid .ico { background: var(--gold); color: #fff; box-shadow: none; }
        .statrow.off .v { color: var(--ink-3); font-weight: 500; }

        /* Контакти */
        .sub-h { font-size: 10.5px; font-weight: 700; color: var(--ink-3); margin: 18px 0 6px; text-transform: uppercase; letter-spacing: .06em; }
        .contact { display: flex; align-items: center; gap: 10px; border-radius: 11px; padding: 9px 11px; margin-top: 6px; background: var(--inset); }
        .contact .lab { flex: none; font-size: 10.5px; font-weight: 700; color: var(--ink-3); min-width: 50px; text-transform: uppercase; letter-spacing: .03em; }
        .contact .val { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .contact .val a { text-decoration: none; }
        .copy { flex: none; cursor: pointer; border: 0; background: none; padding: 6px; border-radius: 8px; color: var(--ink-3); display: inline-flex; }
        .copy:hover { color: var(--accent); background: var(--panel); }
        .copy svg { width: 15px; height: 15px; }
        .placeholder { color: var(--ink-3); border: 1px dashed var(--line); border-radius: var(--r-sm); padding: 30px 18px; text-align: center; background: var(--inset); line-height: 1.6; }

        #toast { position: fixed; right: 24px; bottom: 24px; background: var(--ink); color: #fff; padding: 12px 17px;
            border-radius: 12px; font-size: 13px; font-weight: 600; opacity: 0; transform: translateY(10px);
            transition: .2s; pointer-events: none; z-index: 60; box-shadow: 0 10px 30px rgba(20,22,28,.25); }
        #toast.show { opacity: 1; transform: none; }
    </style>
</head>
<body>
    <div class="topbar">
        <div class="brand">
            <span class="mark">D</span>
            <div>
                <h1>Розсилка адвокатам</h1>
                <div class="sub">обери → повідомлення → скопіюй → познач надіслане</div>
            </div>
        </div>
        <div class="nav">
            <span class="pill">кампанія <b>{{ $utm['campaign'] }}</b></span>
            <a class="navlink accent" href="{{ route('outreach.stats') }}">Статистика</a>
            <a class="navlink" href="{{ config('outreach.public_url') }}" target="_blank" rel="noopener">На сайт</a>
        </div>
    </div>

    <div class="wrap">
        {{-- Колонка 1: адвокати --}}
        <div class="panel">
            <div class="phead">
                <h2>Адвокати</h2>
                <span class="count" id="totalCount"></span>
            </div>
            <div class="pbody" style="padding-bottom:0">
                <div class="search">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
                    <input type="text" id="search" placeholder="Пошук за ім'ям або містом…" autocomplete="off">
                </div>
                <div class="filters">
                    <label class="fchip" id="chipDossier"><input type="checkbox" id="onlyDossier"> з досьє</label>
                    <label class="fchip" id="chipSent"><input type="checkbox" id="hideSent"> сховати надіслані</label>
                    <span class="finfo" id="filterInfo"></span>
                </div>
            </div>
            <div class="list" id="list"></div>
            <div class="legend">
                <span><i class="dot d-link">↗</i> перейшов</span>
                <span><i class="dot d-acc">✓</i> акаунт</span>
                <span><i class="dot d-paid">₴</i> оплатив</span>
                <span><i class="dot" style="background:var(--gold);width:11px;height:11px;border-radius:50%"></i> є досьє</span>
            </div>
        </div>

        {{-- Колонка 2: композер --}}
        <div>
            <div class="panel">
                <div class="phead">
                    <h2>Повідомлення</h2>
                    <span class="selname" id="selName" style="display:none"></span>
                </div>
                <div class="pbody">
                    <div class="tabs" id="tabs"></div>
                    <textarea id="msg" rows="10"></textarea>
                    <div class="toolbar">
                        <button type="button" class="btn btn-primary" id="copyBtn" disabled>
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                            <span id="copyLabel">Скопіювати</span>
                        </button>
                        <button type="button" class="btn btn-outline" id="sentBtn" disabled>
                            <span>Позначити надісланим</span>
                        </button>
                        <div class="spacer"></div>
                        <button type="button" class="btn btn-ghost" id="resetBtn">↻ Скинути</button>
                        <span class="flag-dirty" id="dirtyFlag" style="display:none">відредаговано</span>
                    </div>
                    <p class="hint" id="msgHint">Оберіть адвоката ліворуч — ім'я та посилання з UTM підставляться автоматично.</p>
                </div>
            </div>

            <div class="panel">
                <div class="phead"><h2>Посилання з UTM</h2></div>
                <div class="pbody">
                    <div class="linkrow">
                        <input type="text" id="utmLink" readonly value="—">
                        <button type="button" class="btn btn-outline" id="copyLinkBtn" disabled style="padding:11px 15px">Копіювати</button>
                    </div>
                    <p class="hint">Лінк уже всередині повідомлення. За його UTM-мітками (<code>utm_content</code> = повідомлення) збирається статистика: хто перейшов, що робив, де вийшов.</p>
                </div>
            </div>
        </div>

        {{-- Колонка 3: профіль --}}
        <div class="panel">
            <div class="phead"><h2>Профіль</h2></div>
            <div class="pbody">
                <div id="contactsPlaceholder" class="placeholder">Обери адвоката, щоб побачити статус і контакти.</div>
                <div id="contacts" style="display:none"></div>
            </div>
        </div>
    </div>

    <div id="toast"></div>

    <script>
        const LAWYERS = {!! json_encode($lawyers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) !!};
        const MESSAGES = {!! json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) !!};
        const UTM = {!! json_encode($utm, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!};
        const VISIBLE_LIMIT = 300;
        const VERDICT_WORD = { green: 'чисте', yellow: 'з нюансами', red: 'проблемне' };

        const byId = LAWYERS.reduce((m, l) => (m[l.id] = l, m), {});
        const withDossier = LAWYERS.filter(l => l.has_dossier).length;

        const SENT_KEY = 'outreach_sent_' + UTM.campaign;
        let sent = new Set(JSON.parse(localStorage.getItem(SENT_KEY) || '[]'));
        const saveSent = () => localStorage.setItem(SENT_KEY, JSON.stringify([...sent]));

        let selectedId = null, msgIndex = 0, dirty = false;

        const $ = id => document.getElementById(id);
        const esc = s => (s == null ? '' : String(s)).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));

        // Монограма-аватар: перша буква + детермінований пастельний колір.
        function initial(name) { const m = (name || '').match(/\p{L}|\p{N}/u); return m ? m[0].toUpperCase() : '—'; }
        function hue(str) { let h = 0; for (let i = 0; i < (str || '').length; i++) h = (h * 31 + str.charCodeAt(i)) >>> 0; return h % 360; }
        function avaStyle(name) { const h = hue(name); return '--c-bg:hsl(' + h + ' 52% 94%);--c-fg:hsl(' + h + ' 42% 40%)'; }

        $('totalCount').textContent = LAWYERS.length.toLocaleString('uk') + ' у базі';

        // ── Сигнали (тільки активні — щоб список лишався спокійним) ──
        function sigDots(l) {
            let out = '';
            if (l.clicked) out += '<i class="dot d-link" title="Перейшов по посиланню">↗</i>';
            if (l.account === 'verified') out += '<i class="dot d-acc" title="Підтвердив акаунт">✓</i>';
            else if (l.account === 'claimed') out += '<i class="dot d-warn" title="Подав заявку на акаунт">~</i>';
            if (l.paid) out += '<i class="dot d-paid" title="Оплатив PRO">₴</i>';
            if (sent.has(l.id)) out += '<i class="dot d-sent" title="Надіслано">✓</i>';
            return out ? '<span class="rsig">' + out + '</span>' : '';
        }

        // ── Вкладки повідомлень ──
        MESSAGES.forEach((m, i) => {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'tab' + (i === 0 ? ' active' : '');
            b.textContent = (i + 1) + '. ' + m.label;
            b.addEventListener('click', () => selectMessage(i));
            $('tabs').appendChild(b);
        });

        // ── Список ──
        function filtered() {
            const t = $('search').value.trim().toLowerCase();
            const onlyDossier = $('onlyDossier').checked, hideSent = $('hideSent').checked;
            return LAWYERS.filter(l => {
                if (onlyDossier && !l.has_dossier) return false;
                if (hideSent && sent.has(l.id)) return false;
                if (!t) return true;
                return (l.name && l.name.toLowerCase().includes(t)) || (l.city && l.city.toLowerCase().includes(t));
            });
        }
        function ratingText(l) { return l.reviews > 0 ? ('★ ' + l.rating.toFixed(1) + ' · ' + l.reviews) : 'без відгуків'; }
        function updateCounters() { $('filterInfo').textContent = 'надіслано ' + sent.size + ' · з досьє ' + withDossier; }

        function renderList() {
            const items = filtered(), box = $('list');
            if (items.length === 0) { box.innerHTML = '<p class="empty">Нічого не знайдено.</p>'; return; }
            let html = '';
            items.slice(0, VISIBLE_LIMIT).forEach(l => {
                const cls = ['row'];
                if (l.id === selectedId) cls.push('active');
                if (sent.has(l.id)) cls.push('sent');
                const sub = l.active
                    ? esc(l.city || '—') + ' · <span class="rate">' + esc(ratingText(l)) + '</span>'
                    : '<span class="inact">неактивний — лінк не відкриється</span>';
                html += '<button type="button" class="' + cls.join(' ') + '" data-id="' + l.id + '">' +
                    '<span class="ava' + (l.has_dossier ? ' dossier' : '') + '" style="' + avaStyle(l.name) + '">' + esc(initial(l.name)) + '</span>' +
                    '<span class="rmain"><span class="nm">' + esc(l.name) + '</span><span class="rsub">' + sub + '</span></span>' +
                    sigDots(l) + '</button>';
            });
            if (items.length > VISIBLE_LIMIT) html += '<p class="list-more">Показано ' + VISIBLE_LIMIT + ' з ' + items.length + ' — уточни пошук</p>';
            box.innerHTML = html;
        }
        $('list').addEventListener('click', e => {
            const row = e.target.closest('.row');
            if (row) selectLawyer(parseInt(row.dataset.id, 10));
        });
        $('search').addEventListener('input', renderList);
        function bindChip(chipId, cbId) {
            const cb = $(cbId), chip = $(chipId);
            cb.addEventListener('change', () => { chip.classList.toggle('on', cb.checked); renderList(); });
        }
        bindChip('chipDossier', 'onlyDossier');
        bindChip('chipSent', 'hideSent');

        // ── UTM ──
        function buildLink(lawyer, msgId) {
            if (!lawyer || !lawyer.url) return '';
            try {
                const u = new URL(lawyer.url);
                u.searchParams.set('utm_source', UTM.source);
                u.searchParams.set('utm_medium', UTM.medium);
                u.searchParams.set('utm_campaign', UTM.campaign);
                u.searchParams.set('utm_content', msgId);
                return u.toString();
            } catch (e) { return lawyer.url; }
        }

        function buildMessage() {
            const body = MESSAGES[msgIndex] ? MESSAGES[msgIndex].body : '';
            const s = byId[selectedId];
            if (!s) return body;
            const name = s.name || '', link = buildLink(s, MESSAGES[msgIndex].id);
            let t = body.split('{імя}').join(name).split("{ім'я}").join(name).split('{ім’я}').join(name)
                        .split('{name}').join(name).split('{посилання}').join(link)
                        .split('{профіль}').join(link).split('{link}').join(link);
            const hasLink = ['{посилання}', '{профіль}', '{link}'].some(k => body.includes(k));
            if (!hasLink && link) t = t.replace(/\s+$/, '') + '\n\n' + name + ': ' + link;
            return t;
        }
        function updateSentBtn() {
            const on = selectedId != null && sent.has(selectedId), btn = $('sentBtn');
            btn.disabled = selectedId == null;
            btn.classList.toggle('is-sent', on);
            btn.querySelector('span').textContent = on ? '✓ Надіслано' : 'Позначити надісланим';
        }
        function regenerate() {
            $('msg').value = buildMessage();
            dirty = false; $('dirtyFlag').style.display = 'none';
            const s = byId[selectedId], link = s ? buildLink(s, MESSAGES[msgIndex].id) : '';
            $('utmLink').value = link || '—';
            const ready = !!s;
            $('copyBtn').disabled = !ready;
            $('copyLinkBtn').disabled = !ready;
            $('msgHint').style.display = ready ? 'none' : '';
            updateSentBtn();
        }
        function selectMessage(i) {
            msgIndex = i;
            document.querySelectorAll('#tabs .tab').forEach((el, idx) => el.classList.toggle('active', idx === i));
            regenerate();
        }
        function selectLawyer(id) {
            selectedId = id;
            const s = byId[id], tag = $('selName');
            tag.textContent = s.name; tag.style.display = '';
            regenerate(); renderList(); renderContacts(s);
        }
        function toggleSent() {
            if (selectedId == null) return;
            if (sent.has(selectedId)) sent.delete(selectedId); else sent.add(selectedId);
            saveSent(); updateSentBtn(); updateCounters(); renderList();
            if (byId[selectedId]) renderContacts(byId[selectedId]);
        }

        $('msg').addEventListener('input', () => { dirty = true; $('dirtyFlag').style.display = ''; });
        $('resetBtn').addEventListener('click', regenerate);
        $('sentBtn').addEventListener('click', toggleSent);

        // ── Toast / copy ──
        let toastT = null;
        function toast(msg) {
            const el = $('toast'); el.textContent = msg; el.classList.add('show');
            clearTimeout(toastT); toastT = setTimeout(() => el.classList.remove('show'), 1500);
        }
        function copy(text, label) {
            if (!text || text === '—') return;
            navigator.clipboard.writeText(text).then(() => toast(label || 'Скопійовано'));
        }
        $('copyBtn').addEventListener('click', () => {
            if ($('copyBtn').disabled) return;
            copy($('msg').value, 'Повідомлення скопійовано');
            $('copyLabel').textContent = 'Скопійовано ✓';
            setTimeout(() => $('copyLabel').textContent = 'Скопіювати', 1500);
        });
        $('copyLinkBtn').addEventListener('click', () => copy($('utmLink').value, 'Посилання скопійовано'));

        // ── Профіль ──
        const COPY_SVG = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>';
        function contactRow(label, value, href, copyLabel) {
            const val = href ? '<a href="' + esc(href) + '" target="_blank" rel="noopener">' + esc(value) + '</a>' : esc(value);
            return '<div class="contact"><span class="lab">' + esc(label) + '</span><span class="val">' + val + '</span>' +
                '<button type="button" class="copy" data-copy="' + esc(value) + '" data-label="' + esc(copyLabel) + '" title="Скопіювати">' + COPY_SVG + '</button></div>';
        }
        function statusPanel(s) {
            const acc = s.account === 'verified'
                ? ['on-acc', '✓', 'Підтвердив права']
                : (s.account === 'claimed' ? ['on-warn', '~', 'Подав заявку'] : ['off', '·', 'Не заявляв про профіль']);
            const row = (cls, ico, k, v) =>
                '<div class="statrow ' + cls + '"><span class="ico">' + ico + '</span>' +
                '<span class="txt"><span class="k">' + k + '</span><span class="v">' + v + '</span></span></div>';
            return '<div class="statgrid">' +
                row(s.clicked ? 'on-link' : 'off', '↗', 'Перехід по посиланню', s.clicked ? 'Так, переходив' : 'Ще не переходив') +
                row(acc[0], acc[1], 'Акаунт', acc[2]) +
                row(s.paid ? 'on-paid' : 'off', '₴', 'Оплата PRO', s.paid ? 'Оплатив' : 'Не оплачував') +
                '</div>';
        }
        function renderContacts(s) {
            $('contactsPlaceholder').style.display = 'none';
            const box = $('contacts'); box.style.display = '';
            let html = '<div class="prof-head">' +
                '<span class="ava' + (s.has_dossier ? ' dossier' : '') + '" style="' + avaStyle(s.name) + '">' + esc(initial(s.name)) + '</span>' +
                '<div><div class="nm">' + esc(s.name) + '</div>' + (s.city ? '<div class="city">' + esc(s.city) + '</div>' : '') + '</div></div>';
            html += '<div class="badges">' +
                '<span class="badge">' + esc(ratingText(s)) + '</span>' +
                (s.has_dossier ? '<span class="badge gold">Досьє' + (VERDICT_WORD[s.verdict] ? ': ' + VERDICT_WORD[s.verdict] : '') + '</span>' : '<span class="badge">без досьє</span>') +
                (sent.has(s.id) ? '<span class="badge ok">✓ надіслано</span>' : '') + '</div>';
            html += statusPanel(s);
            if (!s.active) html += '<div class="warn-line">Профіль неактивний — публічне посилання поки не відкривається.</div>';
            html += '<div class="sub-h">Контакти</div>';
            if (s.url) html += contactRow('Профіль', s.url, s.url, 'Посилання скопійовано');
            if (s.phone) html += contactRow('Телефон', s.phone, 'tel:' + s.phone, 'Телефон скопійовано');
            if (s.email) html += contactRow('Email', s.email, 'mailto:' + s.email, 'Email скопійовано');
            if (s.website) html += contactRow('Сайт', s.website, s.website, 'Сайт скопійовано');
            if (s.socials && s.socials.length) {
                html += '<div class="sub-h">Соцмережі</div>';
                s.socials.forEach(soc => { html += contactRow(soc.label, soc.url, soc.url, soc.label + ' скопійовано'); });
            }
            if (!s.phone && !s.email && !s.website && (!s.socials || !s.socials.length))
                html += '<p class="count" style="margin-top:8px">Контактів немає — лишається лише посилання на профіль.</p>';
            box.innerHTML = html;
        }
        $('contacts').addEventListener('click', e => {
            const btn = e.target.closest('.copy');
            if (btn) copy(btn.dataset.copy, btn.dataset.label);
        });

        updateCounters(); renderList(); regenerate();
    </script>
</body>
</html>
