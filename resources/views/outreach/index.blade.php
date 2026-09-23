<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
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
        .nav-badge { display: inline-flex; align-items: center; justify-content: center; min-width: 18px; height: 18px; padding: 0 5px;
            margin-left: 6px; border-radius: 999px; background: #dc2626; color: #fff; font-size: 11px; font-weight: 700; vertical-align: middle; }

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
        .email-draft-grid { display: grid; gap: 12px; }
        .email-draft-field { display: grid; gap: 6px; }
        .email-draft-field span { font-size: 11px; font-weight: 700; color: var(--ink-3); text-transform: uppercase; letter-spacing: .06em; }
        .email-draft-field input, .email-draft-field textarea { background: var(--inset); }
        .msg-preview { margin-top: 10px; padding: 13px 14px; border: 1px solid var(--line); border-radius: var(--r-sm); background: var(--inset); color: var(--ink); line-height: 1.65; white-space: pre-wrap; }
        .msg-preview:empty { display: none; }
        .msg-preview a { font-weight: 650; text-decoration: none; }
        .msg-preview a:hover { text-decoration: underline; }
        .copy-surface { position: fixed; left: -9999px; top: 0; width: 640px; padding: 0; opacity: 0; pointer-events: none; white-space: pre-wrap; }
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
            background: var(--dossier-c, var(--gold)); border: 2px solid var(--panel); }
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
        .dot.d-step { background: var(--accent-soft); color: var(--accent-600); width: auto; min-width: 19px; padding: 0 6px; font-size: 10px; letter-spacing: .02em; }
        .dot.d-due { background: var(--warn); color: #fff; border-radius: 50%; width: 18px; height: 18px; font-size: 11px; }
        .dot.d-no-tg { background: #fee2e2; color: #b91c1c; width: auto; min-width: 19px; padding: 0 6px; font-size: 10px; letter-spacing: .02em; }

        /* Журнал дотиків у панелі профілю */
        .hrow { display: flex; align-items: center; gap: 10px; padding: 8px 11px; border-radius: 10px; background: var(--inset); margin-top: 6px; font-size: 12.5px; }
        .hrow .hs { flex: none; font-weight: 700; min-width: 24px; color: var(--accent-600); }
        .hrow.done { background: var(--ok-soft); } .hrow.done .hs { color: var(--ok); }
        .hrow.due { background: var(--warn-soft); } .hrow.due .hs { color: var(--warn); }
        .hrow .hd { margin-left: auto; color: var(--ink-2); font-variant-numeric: tabular-nums; white-space: nowrap; }

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

        /* Вибір сценарію (послідовності) */
        .seqbar { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 10px; }
        .seqbtn { cursor: pointer; font: inherit; font-size: 13px; font-weight: 650; border: 1px solid var(--line);
            background: #fff; color: var(--ink-2); border-radius: 999px; padding: 7px 15px; transition: .12s; }
        .seqbtn:hover { border-color: var(--ink-3); color: var(--ink); }
        .seqbtn.active { background: var(--accent); border-color: transparent; color: #fff; box-shadow: 0 2px 8px rgba(47,107,255,.3); }
        .seqdesc { color: var(--ink-3); font-size: 12px; margin: 0 0 14px; line-height: 1.5; }

        /* Вкладки-кроки повідомлень */
        .tabs { display: flex; flex-wrap: wrap; gap: 4px; margin-bottom: 14px; padding: 4px; background: var(--inset); border-radius: 13px; }
        .tab { cursor: pointer; font: inherit; font-size: 13px; font-weight: 600; border: 0; background: transparent; color: var(--ink-2);
            border-radius: 10px; padding: 8px 14px; transition: .12s; display: inline-flex; align-items: center; gap: 7px; }
        .tab:hover { color: var(--ink); }
        .tab.active { background: #fff; color: var(--accent); box-shadow: var(--sh); }
        .tab .day { font-size: 11px; font-weight: 700; color: var(--ink-3); background: var(--line-2); border-radius: 6px; padding: 1px 6px; }
        .tab.active .day { color: var(--accent-600); background: var(--accent-soft); }
        .tab .sentmark { font-size: 11px; font-weight: 800; color: var(--ok); }

        /* Індикатор кольорового варіанта повідомлення про досьє */
        .variant-note { display: flex; align-items: center; gap: 8px; margin: -4px 0 12px; padding: 8px 12px; border-radius: 10px;
            font-size: 12.5px; font-weight: 600; background: var(--inset); color: var(--ink-2); border: 1px solid var(--line); }
        .variant-note.v-green { background: var(--ok-soft); color: var(--ok); border-color: transparent; }
        .variant-note.v-yellow { background: var(--warn-soft); color: var(--warn); border-color: transparent; }
        .variant-note.v-red { background: #fee2e2; color: #b91c1c; border-color: transparent; }

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
        .badge.warn { background: var(--warn-soft); color: var(--warn); }
        .badge.red { background: #fee2e2; color: #b91c1c; }
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

        /* Привʼязка: хто власник / хто подавав заявки */
        .bind { margin-top: 10px; display: flex; flex-direction: column; gap: 6px; }
        .bind-row { display: flex; align-items: flex-start; gap: 10px; padding: 9px 11px; border-radius: 11px; background: var(--inset); }
        .bind-row.owner { background: var(--ok-soft); }
        .bind-row.claim-pending { background: var(--warn-soft); }
        .bind-row .bi { flex: none; width: 26px; height: 26px; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; background: #fff; box-shadow: inset 0 0 0 1px var(--line); }
        .bind-row.owner .bi { background: var(--ok); color: #fff; box-shadow: none; }
        .bind-row.claim-pending .bi { background: var(--warn); color: #fff; box-shadow: none; }
        .bind-row .bt { flex: 1; min-width: 0; }
        .bind-row .brole { font-size: 10.5px; color: var(--ink-3); text-transform: uppercase; letter-spacing: .04em; }
        .bind-row .bname { font-weight: 650; font-size: 13px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .bind-row .bmail { font-size: 12px; color: var(--ink-2); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .bind-row .bmail a { text-decoration: none; }
        .bind-row .bwhen { font-size: 11px; color: var(--ink-3); margin-top: 2px; }
        .bind-row .bstatus { flex: none; font-size: 10.5px; font-weight: 700; padding: 2px 8px; border-radius: 999px; background: #fff; color: var(--ink-2); box-shadow: inset 0 0 0 1px var(--line); align-self: center; }
        .bind-row.claim-pending .bstatus { background: var(--warn); color: #fff; box-shadow: none; }
        .bind-empty { font-size: 12.5px; color: var(--ink-3); padding: 9px 11px; border: 1px dashed var(--line); border-radius: 11px; background: var(--inset); }

        /* Контакти */
        .sub-h { font-size: 10.5px; font-weight: 700; color: var(--ink-3); margin: 18px 0 6px; text-transform: uppercase; letter-spacing: .06em; }
        .contact { display: flex; align-items: center; gap: 10px; border-radius: 11px; padding: 9px 11px; margin-top: 6px; background: var(--inset); }
        .contact .lab { flex: none; font-size: 10.5px; font-weight: 700; color: var(--ink-3); min-width: 50px; text-transform: uppercase; letter-spacing: .03em; }
        .contact .val { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .contact .val a { text-decoration: none; }
        .contact-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
        .mini-toggle { cursor: pointer; border: 1px solid var(--line); background: #fff; color: var(--ink-2); border-radius: 999px;
            padding: 7px 12px; font: inherit; font-size: 12px; font-weight: 650; transition: .12s; }
        .mini-toggle:hover { border-color: var(--accent); color: var(--accent); }
        .mini-toggle.on { border-color: transparent; background: #fee2e2; color: #b91c1c; }
        .mini-toggle[disabled] { opacity: .55; cursor: wait; }
        .copy { flex: none; cursor: pointer; border: 0; background: none; padding: 6px; border-radius: 8px; color: var(--ink-3); display: inline-flex; }
        .copy:hover { color: var(--accent); background: var(--panel); }
        .copy svg { width: 15px; height: 15px; }

        /* Редаговані контакти прямо в панелі профілю */
        .email-edit { margin-top: 6px; }
        .email-edit .lab { display: block; font-size: 10.5px; font-weight: 700; color: var(--ink-3); text-transform: uppercase; letter-spacing: .03em; margin-bottom: 5px; }
        .email-edit .field { display: flex; gap: 8px; align-items: center; }
        .email-edit input[type=email], .email-edit input[type=text] { flex: 1; min-width: 0; font: inherit; color: var(--ink); border: 1px solid var(--line);
            border-radius: var(--r-sm); padding: 9px 12px; background: #fff; outline: none; transition: border-color .12s, box-shadow .12s; }
        .email-edit input[type=email]:focus, .email-edit input[type=text]:focus { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-soft); }
        .email-edit input.is-invalid { border-color: #dc2626; box-shadow: 0 0 0 3px #fee2e2; }
        .email-save { flex: none; cursor: pointer; border: 1px solid var(--line); background: #fff; color: var(--ink-2); border-radius: var(--r-sm);
            padding: 9px 14px; font: inherit; font-size: 12.5px; font-weight: 650; transition: .12s; }
        .email-save:hover { border-color: var(--accent); color: var(--accent); }
        .email-save.is-saved { border-color: transparent; background: var(--ok-soft); color: var(--ok); }
        .email-save[disabled] { opacity: .55; cursor: wait; }
        .email-edit .note { font-size: 11.5px; color: var(--ink-3); margin: 5px 0 0; }
        .email-edit .note.err { color: #b91c1c; }
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
                <h1>Розсилка профілям</h1>
                <div class="sub">обери → повідомлення → скопіюй → познач надіслане</div>
            </div>
        </div>
        <div class="nav">
            <span class="pill">кампанія <b>{{ $utm['campaign'] }}</b></span>
            <a class="navlink" href="{{ route('outreach.notifications') }}">Сповіщення@if(($pendingCount ?? 0) > 0)<span class="nav-badge">{{ $pendingCount }}</span>@endif</a>
            <a class="navlink" href="{{ route('outreach.tasks') }}">Задачі</a>
            <a class="navlink accent" href="{{ route('outreach.stats') }}">Статистика</a>
            <a class="navlink accent" href="{{ url('/rozsylka/email-stats') }}">Email</a>
            <a class="navlink" href="{{ config('outreach.public_url') }}" target="_blank" rel="noopener">На сайт</a>
        </div>
    </div>

    <div class="wrap">
        {{-- Колонка 1: профілі --}}
        <div class="panel">
            <div class="phead">
                <h2>Профілі</h2>
                <span class="count" id="totalCount"></span>
            </div>
            <div class="pbody" style="padding-bottom:0">
                <div class="search">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
                    <input type="text" id="search" placeholder="Пошук за ім'ям або містом…" autocomplete="off">
                </div>
                <div class="filters">
                    <label class="fchip" id="chipDossier"><input type="checkbox" id="onlyDossier"> з досьє</label>
                    <label class="fchip" id="chipDue"><input type="checkbox" id="onlyDue"> ⏰ час дотику</label>
                    <label class="fchip" id="chipNoTelegram"><input type="checkbox" id="onlyNoTelegram"> без Telegram</label>
                    <label class="fchip" id="chipSent"><input type="checkbox" id="hideSent"> сховати опрацьованих</label>
                    <span class="finfo" id="filterInfo"></span>
                </div>
            </div>
            <div class="list" id="list"></div>
            <div class="legend">
                <span><i class="dot d-link">↗</i> перейшов</span>
                <span><i class="dot d-acc">✓</i> акаунт</span>
                <span><i class="dot d-paid">₴</i> оплатив</span>
                <span><i class="dot" style="background:var(--ok);width:11px;height:11px;border-radius:50%"></i> досьє чисте</span>
                <span><i class="dot" style="background:var(--warn);width:11px;height:11px;border-radius:50%"></i> з нюансами</span>
                <span><i class="dot" style="background:#dc2626;width:11px;height:11px;border-radius:50%"></i> проблемне</span>
                <span><i class="dot d-step">d1</i> останній дотик</span>
                <span><i class="dot d-due">⏰</i> час наступного</span>
                <span><i class="dot d-no-tg">TG</i> Telegram відсутній</span>
                <span><i class="dot d-sent">✓</i> завершено</span>
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
                    <div class="seqbar" id="seqbar"></div>
                    <p class="seqdesc" id="seqDesc"></p>
                    <div class="tabs" id="tabs"></div>
                    <div class="variant-note" id="variantNote" style="display:none"></div>
                    <textarea id="msg" rows="10"></textarea>
                    <div class="msg-preview" id="msgPreview" aria-label="Превʼю повідомлення"></div>
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
                    <p class="hint" id="msgHint">Оберіть профіль ліворуч — ім'я та посилання з UTM підставляться автоматично.</p>
                </div>
            </div>

            <div class="panel">
                <div class="phead">
                    <h2>Email-чернетка</h2>
                    <span class="flag-dirty" id="emailDirtyFlag" style="display:none">збережено</span>
                </div>
                <div class="pbody">
                    <div class="email-draft-grid">
                        <label class="email-draft-field">
                            <span>Тема</span>
                            <input type="text" id="emailSubject" placeholder="Тема листа">
                        </label>
                        <label class="email-draft-field">
                            <span>Текст листа</span>
                            <textarea id="emailMsg" rows="11" placeholder="Оберіть профіль і крок — текст підставиться автоматично."></textarea>
                        </label>
                    </div>
                    <div class="toolbar">
                        <button type="button" class="btn btn-outline" id="copyEmailBtn" disabled>Скопіювати email</button>
                        <div class="spacer"></div>
                        <button type="button" class="btn btn-ghost" id="resetEmailBtn">↻ Скинути email</button>
                    </div>
                    <p class="hint">Це ручна чернетка для email-розсилки. Вона зберігається локально для поточного сценарію, кроку й варіанта досьє; автоматичну відправку підключимо окремо.</p>
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
                <div id="contactsPlaceholder" class="placeholder">Обери профіль, щоб побачити статус і контакти.</div>
                <div id="contacts" style="display:none"></div>
            </div>
        </div>
    </div>

    <div id="toast"></div>
    <div class="copy-surface" id="copySurface" contenteditable="true" aria-hidden="true"></div>

    <script>
        const LAWYERS = {!! json_encode($lawyers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) !!};
        const SEQUENCES = {!! json_encode($sequences, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) !!};
        const UTM = {!! json_encode($utm, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!};
        const PROFILE_STATUS_URL = @json(route('outreach.status'));
        const PROFILE_FLAGS_URL_TEMPLATE = @json(route('outreach.profile-flags', ['profile' => '__PROFILE_ID__']));
        const PROFILE_EMAIL_URL_TEMPLATE = @json(route('outreach.profile-email', ['profile' => '__PROFILE_ID__']));
        const VISIBLE_LIMIT = 300;
        const TEMPLATE_KEY = 'outreach_message_templates_' + UTM.campaign;
        const EMAIL_TEMPLATE_KEY = 'outreach_email_templates_' + UTM.campaign;
        const VERDICT_WORD = { green: 'чисте', yellow: 'з нюансами', red: 'проблемне' };
        // Колір досьє за вердиктом: зелене/жовте/червоне; невідомий вердикт — золотий.
        const VERDICT_COLOR = { green: 'var(--ok)', yellow: 'var(--warn)', red: '#dc2626' };
        const VERDICT_BADGE = { green: 'ok', yellow: 'warn', red: 'red' };
        const VERDICT_EMOJI = { green: '🟢', yellow: '🟡', red: '🔴' };
        const VARIANT_LABEL = {
            green: '🟢 Варіант для чистого досьє',
            yellow: '🟡 Варіант для досьє з нюансами',
            red: '🔴 Варіант для проблемного досьє',
        };
        let templateOverrides = null;
        try { templateOverrides = JSON.parse(localStorage.getItem(TEMPLATE_KEY) || 'null'); } catch (e) {}
        if (!templateOverrides || typeof templateOverrides !== 'object' || Array.isArray(templateOverrides)) templateOverrides = {};
        const saveTemplateOverrides = () => localStorage.setItem(TEMPLATE_KEY, JSON.stringify(templateOverrides));
        let emailTemplateOverrides = null;
        try { emailTemplateOverrides = JSON.parse(localStorage.getItem(EMAIL_TEMPLATE_KEY) || 'null'); } catch (e) {}
        if (!emailTemplateOverrides || typeof emailTemplateOverrides !== 'object' || Array.isArray(emailTemplateOverrides)) emailTemplateOverrides = {};
        const saveEmailTemplateOverrides = () => localStorage.setItem(EMAIL_TEMPLATE_KEY, JSON.stringify(emailTemplateOverrides));
        // Тіло кроку з урахуванням кольору досьє: якщо в кроку є variants і в
        // профілю відоме дос'є+вердикт — беремо колірний варіант, інакше body.
        function activeVerdict(step, s) {
            if (step && step.variants && s && s.has_dossier && s.verdict && step.variants[s.verdict]) return s.verdict;
            return null;
        }
        function templateKey(step, s) {
            const seq = curSeq();
            const variant = activeVerdict(step, s) || 'base';
            return [seq.id || seqIndex, step.id || stepIndex, variant].join(':');
        }
        function defaultBody(step, s) {
            const v = activeVerdict(step, s);
            return (v ? step.variants[v] : (step.body || ''));
        }
        function resolveBody(step, s) {
            const key = templateKey(step, s);
            return Object.prototype.hasOwnProperty.call(templateOverrides, key)
                ? templateOverrides[key]
                : defaultBody(step, s);
        }
        function preferredLinkPlaceholder(step, s) {
            const source = defaultBody(step, s);
            if (source.includes('{досьє}')) return '{досьє}';
            if (source.includes('{профіль}')) return '{профіль}';
            if (source.includes('{link}')) return '{link}';
            return '{посилання}';
        }
        function preferredNamePlaceholder(step, s) {
            const source = defaultBody(step, s);
            if (source.includes('{ім’я}')) return '{ім’я}';
            if (source.includes("{ім'я}")) return "{ім'я}";
            return '{імя}';
        }
        function textToTemplateBody(text) {
            const step = curStep();
            const s = selectedId != null ? byId[selectedId] : null;
            let body = String(text || '');
            if (s) {
                const link = buildLink(s, step.id);
                if (link) body = body.split(link).join(preferredLinkPlaceholder(step, s));
                const name = s.name || '';
                if (name && ['{імя}', "{ім'я}", '{ім’я}', '{name}'].some(token => defaultBody(step, s).includes(token))) {
                    body = body.split(name).join(preferredNamePlaceholder(step, s));
                }
            }
            return body;
        }
        function saveCurrentTemplateFromInput() {
            const step = curStep();
            const s = selectedId != null ? byId[selectedId] : null;
            const key = templateKey(step, s);
            const body = textToTemplateBody($('msg').value);
            if (body === defaultBody(step, s)) {
                delete templateOverrides[key];
            } else {
                templateOverrides[key] = body;
            }
            saveTemplateOverrides();
        }
        function resetCurrentTemplate() {
            const step = curStep();
            const s = selectedId != null ? byId[selectedId] : null;
            delete templateOverrides[templateKey(step, s)];
            saveTemplateOverrides();
            regenerate();
            toast('Шаблон скинуто');
        }
        function defaultEmailSubject(step, s) {
            const seq = curSeq();
            if ((seq.id || '') === 'claimed_pro') {
                return activeVerdict(step, s) === 'green'
                    ? 'Гарний рейтинг на Dovira — використайте його для звернень'
                    : 'Профіль підтверджено — час оформити підписку';
            }
            if ((step.id || '').endsWith('2')) return 'Нагадуємо про ваш профіль на Dovira';
            if ((step.id || '').endsWith('3')) return 'Останнє нагадування про профіль на Dovira';
            return 'Ваш профіль на Dovira';
        }
        function defaultEmailDraft(step, s) {
            return { subject: defaultEmailSubject(step, s), body: defaultBody(step, s) };
        }
        function emailTemplateKey(step, s) {
            return templateKey(step, s);
        }
        function resolveEmailDraft(step, s) {
            const key = emailTemplateKey(step, s);
            const base = defaultEmailDraft(step, s);
            const saved = emailTemplateOverrides[key];
            if (!saved || typeof saved !== 'object' || Array.isArray(saved)) return base;
            return {
                subject: Object.prototype.hasOwnProperty.call(saved, 'subject') ? String(saved.subject || '') : base.subject,
                body: Object.prototype.hasOwnProperty.call(saved, 'body') ? String(saved.body || '') : base.body,
            };
        }
        // ── Живі цифри профілю в шаблонах ──────────────────────────────
        // {відгуки} → «90 відгуків», {рейтинг} → «5.0»,
        // {позиція} → «2 місце у категорії «Адвокати» у Києві (із 515)».
        const PROFILE_RANK_URL_TEMPLATE = @json(route('outreach.profile-rank', ['profile' => '__PROFILE_ID__']));
        const rankById = {};
        const rankPending = {};

        function reviewsPhrase(count) {
            const n = Number(count || 0);
            const mod10 = n % 10, mod100 = n % 100;
            let word = 'відгуків';
            if (mod10 === 1 && mod100 !== 11) word = 'відгук';
            else if ([2, 3, 4].includes(mod10) && ![12, 13, 14].includes(mod100)) word = 'відгуки';
            return n + ' ' + word;
        }

        function ratingPhrase(rating) {
            const value = Number(rating || 0);
            return value > 0 ? value.toFixed(1).replace('.', ',') : 'поки без оцінки';
        }

        function ensureRank(s) {
            if (!s || rankById[s.id] !== undefined || rankPending[s.id]) return;
            rankPending[s.id] = true;
            fetch(PROFILE_RANK_URL_TEMPLATE.replace('__PROFILE_ID__', s.id), { headers: { Accept: 'application/json' } })
                .then(r => r.ok ? r.json() : null)
                .then(data => {
                    rankById[s.id] = (data && data.label) ? data.label : null;
                    // Перемальовуємо повідомлення, коли позиція доїхала.
                    if (selectedId === s.id) regenerate();
                })
                .catch(() => { rankById[s.id] = null; })
                .finally(() => { delete rankPending[s.id]; });
        }

        // Поки позиція не відома (або її немає) — прибираємо згадку про неї
        // цілком, щоб у листі не лишалось «та позиція —».
        function applyProfileNumbers(body, s) {
            let text = String(body || '');
            if (!s) return text;

            const rank = rankById[s.id];
            if (text.includes('{позиція}')) {
                ensureRank(s);
                if (rank) {
                    text = text.split('{позиція}').join(rank);
                } else {
                    text = text
                        .split(' та позиція {позиція}').join('')
                        .split(', позиція {позиція}').join('')
                        .split('{позиція}').join('—');
                }
            }

            return text
                .split('{відгуки}').join(reviewsPhrase(s.reviews))
                .split('{рейтинг}').join(ratingPhrase(s.rating));
        }

        function replaceTemplateTokens(body, s, step, linkBuilder) {
            if (!s) return String(body || '');
            const name = s.name || '';
            const link = linkBuilder(s, step.id);
            return applyProfileNumbers(body, s)
                .split('{імя}').join(name)
                .split("{ім'я}").join(name)
                .split('{ім’я}').join(name)
                .split('{name}').join(name)
                .split('{досьє}').join(link)
                .split('{посилання}').join(link)
                .split('{профіль}').join(link)
                .split('{link}').join(link);
        }
        function emailTextToTemplateBody(text) {
            const step = curStep();
            const s = selectedId != null ? byId[selectedId] : null;
            let body = String(text || '');
            if (s) {
                const link = buildEmailLink(s, step.id);
                if (link) body = body.split(link).join(preferredLinkPlaceholder(step, s));
                const name = s.name || '';
                if (name && ['{імя}', "{ім'я}", '{ім’я}', '{name}'].some(token => defaultBody(step, s).includes(token))) {
                    body = body.split(name).join(preferredNamePlaceholder(step, s));
                }
            }
            return body;
        }
        function updateEmailCopyPayload() {
            const subject = $('emailSubject').value.trim();
            const body = $('emailMsg').value.trim();
            $('emailMsg').dataset.copyPlain = (subject ? 'Тема: ' + subject + '\n\n' : '') + body;
        }
        function saveCurrentEmailDraftFromInput() {
            const step = curStep();
            const s = selectedId != null ? byId[selectedId] : null;
            const key = emailTemplateKey(step, s);
            const draft = {
                subject: $('emailSubject').value,
                body: emailTextToTemplateBody($('emailMsg').value),
            };
            const base = defaultEmailDraft(step, s);
            if (draft.subject === base.subject && draft.body === base.body) {
                delete emailTemplateOverrides[key];
            } else {
                emailTemplateOverrides[key] = draft;
            }
            saveEmailTemplateOverrides();
            updateEmailCopyPayload();
            $('emailDirtyFlag').textContent = 'збережено як email-шаблон';
            $('emailDirtyFlag').style.display = '';
        }
        function resetCurrentEmailDraft() {
            const step = curStep();
            const s = selectedId != null ? byId[selectedId] : null;
            delete emailTemplateOverrides[emailTemplateKey(step, s)];
            saveEmailTemplateOverrides();
            regenerateEmailDraft();
            toast('Email-чернетку скинуто');
        }
        const dossierColor = l => (l && l.has_dossier) ? (VERDICT_COLOR[l.verdict] || 'var(--gold)') : null;
        // Стиль аватара + колір індикатора досьє (якщо є).
        const avaFull = l => avaStyle(l.name) + (l.has_dossier ? ';--dossier-c:' + dossierColor(l) : '');

        const byId = LAWYERS.reduce((m, l) => (m[l.id] = l, m), {});
        const withDossier = LAWYERS.filter(l => l.has_dossier).length;

        // ── Покроковий журнал дотиків: { lawyerId: { stepId: 'YYYY-MM-DD' } } ──
        const STEPS_KEY = 'outreach_steps_' + UTM.campaign;
        const LEGACY_KEY = 'outreach_sent_' + UTM.campaign;
        const MANUAL_KEY = 'outreach_manual_flags_' + UTM.campaign;

        const todayISO = () => { const d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); };
        const addDays = (iso, days) => { const [y, m, dd] = iso.split('-').map(Number); const d = new Date(y, m - 1, dd + days); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); };
        const fmtD = iso => iso ? iso.slice(8, 10) + '.' + iso.slice(5, 7) : '';

        let sentSteps = null;
        try { sentSteps = JSON.parse(localStorage.getItem(STEPS_KEY) || 'null'); } catch (e) {}
        if (!sentSteps || typeof sentSteps !== 'object' || Array.isArray(sentSteps)) {
            sentSteps = {};
            // Міграція старої плоскої позначки «надіслано» (крок невідомий): вважаємо
            // першим дотиком першого сценарію від сьогодні — follow-up стане в чергу сам.
            try {
                const legacy = JSON.parse(localStorage.getItem(LEGACY_KEY) || '[]');
                const firstStep = SEQUENCES[0] && SEQUENCES[0].steps[0] ? SEQUENCES[0].steps[0].id : null;
                if (firstStep && Array.isArray(legacy)) legacy.forEach(id => { sentSteps[id] = { [firstStep]: todayISO() }; });
            } catch (e) {}
            if (Object.keys(sentSteps).length) localStorage.setItem(STEPS_KEY, JSON.stringify(sentSteps));
        }
        const saveSteps = () => localStorage.setItem(STEPS_KEY, JSON.stringify(sentSteps));

        // Ручні позначки оператора: { lawyerId: { noTelegram: true } }.
        let manualFlags = LAWYERS.reduce((m, lawyer) => {
            if (lawyer.no_telegram) m[lawyer.id] = { noTelegram: true };
            return m;
        }, {});
        const saveManualFlags = () => localStorage.setItem(MANUAL_KEY, JSON.stringify(manualFlags));
        const profileFlags = id => manualFlags[id] || {};
        const isTelegramMissing = id => !!profileFlags(id).noTelegram;
        function setTelegramMissing(id, missing) {
            const rec = manualFlags[id] || {};
            if (missing) rec.noTelegram = true; else delete rec.noTelegram;
            if (Object.keys(rec).length) manualFlags[id] = rec; else delete manualFlags[id];
            saveManualFlags();
            if (byId[id]) byId[id].no_telegram = !!missing;
        }
        function applyBackendStatuses(items) {
            if (!Array.isArray(items)) return;
            let changed = false;
            items.forEach(item => {
                const id = parseInt(item.id, 10);
                const lawyer = byId[id];
                if (!lawyer) return;

                const next = {
                    clicked: !!item.clicked,
                    account: item.account || 'none',
                    paid: !!item.paid,
                    noTelegram: !!item.no_telegram,
                };
                const prev = {
                    clicked: !!lawyer.clicked,
                    account: lawyer.account || 'none',
                    paid: !!lawyer.paid,
                    noTelegram: !!lawyer.no_telegram,
                };

                lawyer.clicked = next.clicked;
                lawyer.account = next.account;
                lawyer.paid = next.paid;
                setTelegramMissing(id, next.noTelegram);

                if (prev.clicked !== next.clicked || prev.account !== next.account || prev.paid !== next.paid || prev.noTelegram !== next.noTelegram) {
                    changed = true;
                }
            });

            if (changed) {
                updateCounters();
                renderList();
                if (selectedId != null && byId[selectedId]) renderContacts(byId[selectedId]);
            }
        }
        async function refreshBackendStatuses(silent) {
            try {
                const response = await fetch(PROFILE_STATUS_URL, {
                    headers: { 'Accept': 'application/json' },
                    credentials: 'same-origin',
                });
                if (!response.ok) throw new Error('status-refresh-failed');
                const data = await response.json();
                applyBackendStatuses(data.profiles || []);
            } catch (e) {
                if (!silent) toast('Не вдалося оновити статуси');
            }
        }
        async function persistTelegramMissing(id, missing) {
            const response = await fetch(PROFILE_FLAGS_URL_TEMPLATE.replace('__PROFILE_ID__', encodeURIComponent(id)), {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
                body: JSON.stringify({ no_telegram: !!missing }),
            });
            if (!response.ok) throw new Error('Не вдалося зберегти позначку');
            const data = await response.json();
            setTelegramMissing(id, !!(data.flags && data.flags.no_telegram));
            return data;
        }
        async function persistContact(id, payload) {
            const response = await fetch(PROFILE_EMAIL_URL_TEMPLATE.replace('__PROFILE_ID__', encodeURIComponent(id)), {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
                body: JSON.stringify(payload),
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) {
                const err = new Error('save-failed');
                err.status = response.status;
                err.data = data;
                throw err;
            }
            if (byId[id]) {
                if (Object.prototype.hasOwnProperty.call(data, 'email')) byId[id].email = data.email || null;
                if (Object.prototype.hasOwnProperty.call(data, 'telegram')) byId[id].telegram = data.telegram || null;
                if (Array.isArray(data.socials)) byId[id].socials = data.socials;
                if (data.flags) {
                    setTelegramMissing(id, !!data.flags.no_telegram);
                } else if (data.telegram) {
                    setTelegramMissing(id, false);
                }
            }
            return data;
        }
        function hasTelegram(s) {
            if (s && s.telegram) return true;
            return !!(s && Array.isArray(s.socials) && s.socials.some(soc => {
                const label = String(soc.label || '').toLowerCase();
                const url = String(soc.url || '').toLowerCase();
                return label.includes('telegram') || url.includes('t.me/') || url.includes('telegram.me/') || url.includes('telegram.org/');
            }));
        }

        // stepId → { seqIdx, stepIdx, step } (для прогресу й дедлайнів).
        const STEP_INFO = {};
        SEQUENCES.forEach((s, qi) => s.steps.forEach((st, si) => { STEP_INFO[st.id] = { seqIdx: qi, stepIdx: si, step: st }; }));

        /**
         * Прогрес профілю: останній надісланий крок (його сценарій = «активний»),
         * наступний крок і дата, коли він настає (дата останнього + різниця day).
         */
        function progress(id) {
            const rec = sentSteps[id];
            if (!rec) return null;
            let last = null;
            for (const stepId of Object.keys(rec)) {
                const info = STEP_INFO[stepId];
                if (!info) continue;
                const date = rec[stepId];
                if (!last || date > last.date || (date === last.date && info.stepIdx > last.info.stepIdx)) last = { stepId, date, info };
            }
            if (!last) return null;
            const seq = SEQUENCES[last.info.seqIdx];
            // Незалежні варіанти першого звернення: жодних наступних дотиків.
            if (seq.alternatives) return { last, next: null, dueDate: null, isDue: false, done: true };
            const next = seq.steps[last.info.stepIdx + 1] || null;
            // Розмовний сценарій («Діалог»): наступний крок настає ПО ВІДПОВІДІ,
            // а не за календарем — тому без дати й без «⏰ час дотику».
            if (seq.reply_driven) return { last, next, dueDate: null, isDue: false, done: !next, reply: true };
            const dueDate = next ? addDays(last.date, Math.max(0, (next.day || 0) - (last.info.step.day || 0))) : null;
            return { last, next, dueDate, isDue: !!next && todayISO() >= dueDate, done: !next };
        }

        let selectedId = null, seqIndex = 0, stepIndex = 0, dirty = false;
        const curSeq = () => SEQUENCES[seqIndex] || { steps: [] };
        const curStep = () => curSeq().steps[stepIndex] || { id: '', body: '' };

        const $ = id => document.getElementById(id);
        const esc = s => (s == null ? '' : String(s)).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
        const escAttr = s => esc(s).replace(/'/g, '&#039;');

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
            if (l.paid) out += '<i class="dot d-paid" title="Оплатив підписку">₴</i>';
            if (isTelegramMissing(l.id)) out += '<i class="dot d-no-tg" title="Telegram відсутній">TG</i>';
            const p = progress(l.id);
            if (p) {
                if (p.done) out += '<i class="dot d-sent" title="Послідовність завершено: ' + p.last.stepId + ' · ' + fmtD(p.last.date) + '">✓</i>';
                else out += '<i class="dot d-step" title="' + (p.reply ? 'Останній крок розмови' : 'Останній дотик') + ': ' + p.last.stepId + ' · ' + fmtD(p.last.date) + '">' + esc(p.last.stepId) + '</i>';
                if (p.isDue) out += '<i class="dot d-due" title="Час наступного дотику: ' + p.next.id + ' (з ' + fmtD(p.dueDate) + ')">⏰</i>';
            }
            return out ? '<span class="rsig">' + out + '</span>' : '';
        }

        // ── Сценарії (послідовності) ──
        function renderSeqBar() {
            $('seqbar').innerHTML = SEQUENCES.map((s, i) =>
                '<button type="button" class="seqbtn' + (i === seqIndex ? ' active' : '') + '" data-seq="' + i + '">' + esc(s.label) + '</button>'
            ).join('');
            $('seqDesc').textContent = curSeq().desc || '';
        }
        $('seqbar').addEventListener('click', e => {
            const btn = e.target.closest('.seqbtn');
            if (btn) selectSequence(parseInt(btn.dataset.seq, 10));
        });

        // ── Вкладки-кроки поточного сценарію ──
        function renderTabs() {
            const rec = selectedId != null ? (sentSteps[selectedId] || {}) : {};
            $('tabs').innerHTML = curSeq().steps.map((st, i) =>
                '<button type="button" class="tab' + (i === stepIndex ? ' active' : '') + '" data-step="' + i + '">' +
                esc(st.label) + (typeof st.day === 'number' ? '<span class="day">день ' + st.day + '</span>' : '') +
                (rec[st.id] ? '<span class="sentmark">✓ ' + fmtD(rec[st.id]) + '</span>' : '') +
                '</button>'
            ).join('');
        }
        $('tabs').addEventListener('click', e => {
            const btn = e.target.closest('.tab');
            if (btn) selectStep(parseInt(btn.dataset.step, 10));
        });

        // ── Список ──
        function filtered() {
            const t = $('search').value.trim().toLowerCase();
            const onlyDossier = $('onlyDossier').checked, hideSent = $('hideSent').checked, onlyDue = $('onlyDue').checked, onlyNoTelegram = $('onlyNoTelegram').checked;
            return LAWYERS.filter(l => {
                if (onlyDossier && !l.has_dossier) return false;
                if (onlyNoTelegram && !isTelegramMissing(l.id)) return false;
                const p = progress(l.id);
                if (onlyDue && !(p && p.isDue)) return false;
                // «Опрацьовані» — щось уже слали і зараз дія не потрібна (чекаємо/завершили).
                if (hideSent && p && !p.isDue) return false;
                if (!t) return true;
                return (l.name && l.name.toLowerCase().includes(t)) || (l.city && l.city.toLowerCase().includes(t));
            });
        }
        function ratingText(l) { return l.reviews > 0 ? ('★ ' + l.rating.toFixed(1) + ' · ' + l.reviews) : 'без відгуків'; }
        function updateCounters() {
            const inWork = Object.keys(sentSteps).length;
            const due = LAWYERS.reduce((n, l) => { const p = progress(l.id); return n + (p && p.isDue ? 1 : 0); }, 0);
            const noTelegram = LAWYERS.reduce((n, l) => n + (isTelegramMissing(l.id) ? 1 : 0), 0);
            $('filterInfo').textContent = 'у роботі ' + inWork + ' · ⏰ сьогодні ' + due + ' · без TG ' + noTelegram + ' · з досьє ' + withDossier;
        }

        function renderList() {
            const items = filtered(), box = $('list');
            if (items.length === 0) { box.innerHTML = '<p class="empty">Нічого не знайдено.</p>'; return; }
            let html = '';
            items.slice(0, VISIBLE_LIMIT).forEach(l => {
                const cls = ['row'];
                if (l.id === selectedId) cls.push('active');
                const pr = progress(l.id);
                if (pr && pr.done) cls.push('sent');
                const sub = l.active
                    ? esc(l.city || '—') + ' · <span class="rate">' + esc(ratingText(l)) + '</span>'
                    : '<span class="inact">неактивний — лінк не відкриється</span>';
                html += '<button type="button" class="' + cls.join(' ') + '" data-id="' + l.id + '">' +
                    '<span class="ava' + (l.has_dossier ? ' dossier' : '') + '" style="' + avaFull(l) + '">' + esc(initial(l.name)) + '</span>' +
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
        bindChip('chipDue', 'onlyDue');
        bindChip('chipNoTelegram', 'onlyNoTelegram');
        bindChip('chipSent', 'hideSent');

        // ── UTM ──
        function buildTrackedLink(lawyer, msgId, medium, compactDialog) {
            if (!lawyer || !lawyer.url) return '';
            try {
                const u = new URL(lawyer.url);
                // Розмовний сценарій («Діалог»): коротке посилання /s/{id}/tg —
                // чисте й компактне, серверний редірект сам чіпляє UTM (dm) і
                // веде одразу на блок досьє. У діалозі вставляємо його тільки в
                // кроки, де текст явно містить плейсхолдер посилання.
                if (compactDialog && (curSeq() || {}).reply_driven) return u.origin + '/s/' + lawyer.id + '/tg';
                u.searchParams.set('utm_source', UTM.source);
                u.searchParams.set('utm_medium', medium || UTM.medium);
                u.searchParams.set('utm_campaign', UTM.campaign);
                u.searchParams.set('utm_content', msgId);
                // Група «Досьє» — пряме посилання на профіль (блок досьє). Пряме,
                // а не коротке /s/: Telegram показує прев'ю-картку (OG-теги) саме
                // по прямому URL; редірект прев'ю не гарантує. Клікабельність дає
                // копіювання простим текстом (кнопка «Скопіювати»).
                if ((curSeq() || {}).id === 'dossier') u.hash = 'profile-dossier';
                return u.toString();
            } catch (e) { return lawyer.url; }
        }
        function buildLink(lawyer, msgId) {
            return buildTrackedLink(lawyer, msgId, UTM.medium, true);
        }
        function buildEmailLink(lawyer, msgId) {
            return buildTrackedLink(lawyer, msgId, 'email', false);
        }

        function buildMessagePayload() {
            const step = curStep();
            const s = byId[selectedId];
            let body = resolveBody(step, s);
            if (!s) return { display: body, plain: body, html: esc(body) };
            const name = s.name || '', link = buildLink(s, step.id);
            body = applyProfileNumbers(body, s);
            const richLinkLabel = 'Відкрити профіль';
            const replaceNames = value => value
                .split('{імя}').join(name)
                .split("{ім'я}").join(name)
                .split('{ім’я}').join(name)
                .split('{name}').join(name);
            // display/plain: {досьє} → голий URL (Telegram сам робить його
            // клікабельним, якщо rich-форматування не підхопилось). Гарний
            // анкор «Перевірити досьє» лишається лише в HTML-версії нижче.
            const display = replaceNames(body)
                .split('{досьє}').join(link || richLinkLabel)
                .split('{посилання}').join(link)
                .split('{профіль}').join(link)
                .split('{link}').join(link);
            const plain = replaceNames(body)
                .split('{досьє}').join(link || richLinkLabel)
                .split('{посилання}').join(link)
                .split('{профіль}').join(link)
                .split('{link}').join(link);
            // HTML-версія (rich): усі плейсхолдери → клікабельний АНКОР із
            // людським підписом, а не голий URL. Саме це Telegram Desktop
            // вставляє як гарне посилання.
            const profileLinkLabel = 'Відкрити профіль';
            const anchor = label => link ? '<a href="' + escAttr(link) + '">' + esc(label) + '</a>' : esc(label);
            let html = esc(replaceNames(body));
            html = html
                .split('{досьє}').join(anchor(richLinkLabel))
                .split('{посилання}').join(anchor(profileLinkLabel))
                .split('{профіль}').join(anchor(profileLinkLabel))
                .split('{link}').join(anchor(profileLinkLabel))
                .replace(/\n/g, '<br>');
            const hasLink = ['{посилання}', '{профіль}', '{link}', '{досьє}'].some(k => body.includes(k));
            // Розмовний сценарій («Діалог»): НЕ дописуємо лінк автоматично.
            // Зачіп (dg1) лишається без посилання, а далі лінк зʼявляється лише
            // там, де в тексті явно стоїть {посилання}.
            if ((curSeq() || {}).reply_driven) return { display, plain, html };
            if (!hasLink && link) {
                return {
                    display: display.replace(/\s+$/, '') + '\n\n' + name + ': ' + link,
                    plain: plain.replace(/\s+$/, '') + '\n\n' + name + ': ' + link,
                    html: html.replace(/(<br>|\s)+$/, '') + '<br><br>' + esc(name) + ': <a href="' + escAttr(link) + '">' + esc(link) + '</a>',
                };
            }
            return { display, plain, html };
        }
        function updateSentBtn() {
            const btn = $('sentBtn'), stepId = curStep().id || '—';
            const date = selectedId != null ? (sentSteps[selectedId] || {})[stepId] : null;
            btn.disabled = selectedId == null;
            btn.classList.toggle('is-sent', !!date);
            btn.querySelector('span').textContent = date ? ('✓ ' + stepId + ' · ' + fmtD(date)) : ('Позначити надісланим: ' + stepId);
        }
        function regenerate() {
            const payload = buildMessagePayload();
            $('msg').value = payload.display;
            $('msg').dataset.copyPlain = payload.plain;
            $('msg').dataset.copyHtml = payload.html;
            $('msgPreview').innerHTML = payload.html;
            const hasOverride = Object.prototype.hasOwnProperty.call(templateOverrides, templateKey(curStep(), byId[selectedId] || null));
            dirty = false;
            $('dirtyFlag').textContent = hasOverride ? 'збережений шаблон' : '';
            $('dirtyFlag').style.display = hasOverride ? '' : 'none';
            const s = byId[selectedId], link = s ? buildLink(s, curStep().id) : '';
            $('utmLink').value = link || '—';
            // Індикатор кольорового варіанта повідомлення про досьє.
            const step = curStep(), verdict = activeVerdict(step, s), vn = $('variantNote');
            if (verdict) {
                vn.style.display = ''; vn.className = 'variant-note v-' + verdict; vn.textContent = VARIANT_LABEL[verdict];
            } else if (step.variants && s) {
                vn.style.display = ''; vn.className = 'variant-note';
                vn.textContent = s.has_dossier ? 'Нейтральний варіант — колір досьє невідомий.' : 'Нейтральний варіант — у профілю немає досьє.';
            } else {
                vn.style.display = 'none';
            }
            const ready = !!s;
            $('copyBtn').disabled = !ready;
            $('copyLinkBtn').disabled = !ready;
            $('msgHint').style.display = ready ? 'none' : '';
            updateSentBtn();
            regenerateEmailDraft();
        }
        function regenerateEmailDraft() {
            const step = curStep();
            const s = byId[selectedId] || null;
            const draft = resolveEmailDraft(step, s);
            $('emailSubject').value = replaceTemplateTokens(draft.subject, s, step, buildEmailLink);
            $('emailMsg').value = replaceTemplateTokens(draft.body, s, step, buildEmailLink);
            updateEmailCopyPayload();
            const hasOverride = Object.prototype.hasOwnProperty.call(emailTemplateOverrides, emailTemplateKey(step, s));
            $('emailDirtyFlag').textContent = hasOverride ? 'збережений email-шаблон' : '';
            $('emailDirtyFlag').style.display = hasOverride ? '' : 'none';
            $('copyEmailBtn').disabled = !s;
        }
        function selectSequence(i) {
            seqIndex = i; stepIndex = 0;
            renderSeqBar(); renderTabs(); regenerate();
        }
        function selectStep(i) {
            stepIndex = i;
            renderTabs(); regenerate();
        }
        function selectLawyer(id) {
            selectedId = id;
            const s = byId[id], tag = $('selName');
            tag.textContent = s.name; tag.style.display = '';
            // Автоперехід на «його» сценарій і наступний крок — одразу видно, що слати.
            const p = progress(id);
            if (p) {
                seqIndex = p.last.info.seqIdx;
                stepIndex = p.next ? p.last.info.stepIdx + 1 : p.last.info.stepIdx;
                renderSeqBar();
            }
            renderTabs(); regenerate(); renderList(); renderContacts(s);
        }
        function toggleSent() {
            if (selectedId == null) return;
            const stepId = curStep().id;
            if (!stepId) return;
            const rec = sentSteps[selectedId] || {};
            if (rec[stepId]) delete rec[stepId]; else rec[stepId] = todayISO();
            if (Object.keys(rec).length) sentSteps[selectedId] = rec; else delete sentSteps[selectedId];
            saveSteps(); renderTabs(); updateSentBtn(); updateCounters(); renderList();
            if (byId[selectedId]) renderContacts(byId[selectedId]);
        }

        $('msg').addEventListener('input', () => {
            saveCurrentTemplateFromInput();
            dirty = true;
            $('dirtyFlag').textContent = 'збережено як шаблон';
            $('dirtyFlag').style.display = '';
            $('msg').dataset.copyPlain = $('msg').value;
            $('msg').dataset.copyHtml = esc($('msg').value).replace(/\n/g, '<br>');
            $('msgPreview').innerHTML = $('msg').dataset.copyHtml;
        });
        $('resetBtn').addEventListener('click', resetCurrentTemplate);
        $('sentBtn').addEventListener('click', toggleSent);
        $('emailSubject').addEventListener('input', saveCurrentEmailDraftFromInput);
        $('emailMsg').addEventListener('input', saveCurrentEmailDraftFromInput);
        $('resetEmailBtn').addEventListener('click', resetCurrentEmailDraft);

        // ── Toast / copy ──
        let toastT = null;
        function toast(msg) {
            const el = $('toast'); el.textContent = msg; el.classList.add('show');
            clearTimeout(toastT); toastT = setTimeout(() => el.classList.remove('show'), 1500);
        }
        function copyViaCopyEvent({ plain, html }) {
            const onCopy = e => {
                e.clipboardData.setData('text/plain', plain);
                e.clipboardData.setData('text/html', html || esc(plain).replace(/\n/g, '<br>'));
                e.preventDefault();
            };
            document.addEventListener('copy', onCopy);
            try { return document.execCommand('copy'); }
            finally { document.removeEventListener('copy', onCopy); }
        }
        function copySelectedElement(el) {
            if (!el) return false;
            const selection = window.getSelection();
            const range = document.createRange();
            range.selectNodeContents(el);
            selection.removeAllRanges();
            selection.addRange(range);
            let ok = false;
            try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
            selection.removeAllRanges();
            return ok;
        }
        function copyViaSelection(html) {
            const surface = $('copySurface');
            if (!surface) return false;
            surface.innerHTML = html;
            const ok = copySelectedElement(surface);
            surface.innerHTML = '';
            return ok;
        }
        async function copyRich({ plain, html }, label) {
            if (!plain || plain === '—') return;
            const richHtml = html || esc(plain).replace(/\n/g, '<br>');

            try {
                if (copySelectedElement($('msgPreview')) || copyViaSelection(richHtml) || copyViaCopyEvent({ plain, html: richHtml })) {
                    toast(label || 'Скопійовано');
                    return;
                }
            } catch (e) {}

            try {
                if (window.ClipboardItem && navigator.clipboard?.write) {
                    await navigator.clipboard.write([
                        new ClipboardItem({
                            'text/plain': new Blob([plain], { type: 'text/plain' }),
                            'text/html': new Blob([richHtml], { type: 'text/html' }),
                        }),
                    ]);
                    toast(label || 'Скопійовано');
                    return;
                }
            } catch (e) {}

            navigator.clipboard.writeText(plain).then(() => toast(label || 'Скопійовано'));
        }
        function copy(text, label) {
            if (!text || text === '—') return;
            navigator.clipboard.writeText(text).then(() => toast(label || 'Скопійовано'));
        }
        $('copyBtn').addEventListener('click', () => {
            if ($('copyBtn').disabled) return;
            // ПРОСТИЙ текст (не rich): при вставці в Telegram/Viber URL лишається
            // «голим», і месенджер САМ робить його клікабельним. Форматований
            // (rich) текст цього не давав — лінк вставлявся як звичайний текст.
            const plain = $('msg').dataset.copyPlain || $('msg').value;
            navigator.clipboard.writeText(plain).then(() => toast('Повідомлення скопійовано'));
            $('copyLabel').textContent = 'Скопійовано ✓';
            setTimeout(() => $('copyLabel').textContent = 'Скопіювати', 1500);
        });
        $('copyLinkBtn').addEventListener('click', () => copy($('utmLink').value, 'Посилання скопійовано'));
        $('copyEmailBtn').addEventListener('click', () => {
            if ($('copyEmailBtn').disabled) return;
            const plain = $('emailMsg').dataset.copyPlain || $('emailMsg').value;
            navigator.clipboard.writeText(plain).then(() => toast('Email скопійовано'));
        });

        // ── Профіль ──
        const COPY_SVG = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>';
        function contactRow(label, value, href, copyLabel) {
            const val = href ? '<a href="' + esc(href) + '" target="_blank" rel="noopener">' + esc(value) + '</a>' : esc(value);
            return '<div class="contact"><span class="lab">' + esc(label) + '</span><span class="val">' + val + '</span>' +
                '<button type="button" class="copy" data-copy="' + esc(value) + '" data-label="' + esc(copyLabel) + '" title="Скопіювати">' + COPY_SVG + '</button></div>';
        }
        // Редагований email: підставляємо наявний, дозволяємо вписати/змінити й зберегти в БД.
        function emailEditor(s) {
            const val = s.email || '';
            return '<div class="email-edit">' +
                '<span class="lab">Email</span>' +
                '<div class="field">' +
                '<input type="email" data-email-input value="' + escAttr(val) + '" placeholder="вписати email…" autocomplete="off" spellcheck="false">' +
                '<button type="button" class="email-save" data-save-email>Зберегти</button>' +
                '</div>' +
                '<p class="note" data-email-note>' + (val ? 'Email із профілю — можна змінити.' : 'Email відсутній — впишіть і збережіть у базу.') + '</p>' +
                '</div>';
        }
        function telegramEditor(s) {
            const val = s.telegram || '';
            return '<div class="email-edit">' +
                '<span class="lab">Telegram</span>' +
                '<div class="field">' +
                '<input type="text" data-telegram-input value="' + escAttr(val) + '" placeholder="@username або t.me/username" autocomplete="off" spellcheck="false">' +
                '<button type="button" class="email-save" data-save-telegram>Зберегти</button>' +
                '</div>' +
                '<p class="note" data-telegram-note>' + (val ? 'Telegram із профілю — можна змінити.' : 'Telegram відсутній — впишіть нік і збережіть у базу.') + '</p>' +
                '</div>';
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
                row(s.paid ? 'on-paid' : 'off', '₴', 'Оплата підписки', s.paid ? 'Оплатив' : 'Не оплачував') +
                '</div>';
        }
        // Привʼязка профілю: хто ВЛАСНИК і хто подавав ЗАЯВКИ (імʼя + email).
        const CLAIM_STATUS_LABEL = { pending: 'очікує', approved: 'схвалено', rejected: 'відхилено' };
        function bindingPanel(s) {
            const owner = s.owner;
            const claims = Array.isArray(s.claims) ? s.claims : [];
            let rows = '';
            if (owner) {
                rows += '<div class="bind-row owner"><span class="bi">✓</span><span class="bt">' +
                    '<span class="brole">Власник</span>' +
                    '<span class="bname">' + esc(owner.name) + '</span>' +
                    (owner.email ? '<span class="bmail"><a href="mailto:' + esc(owner.email) + '">' + esc(owner.email) + '</a></span>' : '') +
                    '</span></div>';
            }
            claims.forEach(c => {
                const pending = c.status === 'pending';
                rows += '<div class="bind-row' + (pending ? ' claim-pending' : '') + '"><span class="bi">' + (pending ? '⏳' : '↩') + '</span><span class="bt">' +
                    '<span class="brole">Заявник' + (c.role ? ' · ' + esc(c.role) : '') + '</span>' +
                    '<span class="bname">' + esc(c.name) + '</span>' +
                    (c.email ? '<span class="bmail"><a href="mailto:' + esc(c.email) + '">' + esc(c.email) + '</a></span>' : '') +
                    (c.at ? '<span class="bwhen">' + esc(c.at) + '</span>' : '') +
                    '</span><span class="bstatus">' + esc(CLAIM_STATUS_LABEL[c.status] || c.status) + '</span></div>';
            });
            const head = '<div class="sub-h">Привʼязка</div>';
            if (!rows) return head + '<div class="bind-empty">Профіль ще нічий — ніхто не володіє ним і не подавав заявку.</div>';
            return head + '<div class="bind">' + rows + '</div>';
        }
        /**
         * Журнал дотиків активного сценарію профілю (або обраного зараз, якщо
         * ще нічого не слали): що надіслано і коли, який крок наступний і коли настає.
         */
        function renderHistory(s) {
            const p = progress(s.id);
            const seq = p ? SEQUENCES[p.last.info.seqIdx] : curSeq();
            const rec = sentSteps[s.id] || {};
            let html = '<div class="sub-h">' + (seq.reply_driven ? 'Розмова' : 'Дотики') + ' · ' + esc(seq.label) + '</div>';
            if (!p) html += '<p class="count" style="margin:6px 0 0">Ще не писали — почніть із першого кроку.</p>';
            seq.steps.forEach(st => {
                const date = rec[st.id];
                let cls = '', right = '—';
                if (date) { cls = ' done'; right = '✓ ' + fmtD(date); }
                else if (p && p.next && p.next.id === st.id) {
                    if (p.reply) { cls = ' due'; right = '→ по відповіді'; }
                    else if (p.isDue) { cls = ' due'; right = '⏰ ' + (p.dueDate === todayISO() ? 'сьогодні' : 'з ' + fmtD(p.dueDate)); }
                    else right = 'чекає до ' + fmtD(p.dueDate);
                }
                html += '<div class="hrow' + cls + '"><span class="hs">' + esc(st.id) + '</span><span>' + esc(st.label) + '</span><span class="hd">' + right + '</span></div>';
            });
            return html;
        }

        function renderContacts(s) {
            $('contactsPlaceholder').style.display = 'none';
            const box = $('contacts'); box.style.display = '';
            const telegramMissing = isTelegramMissing(s.id);
            const telegramExists = hasTelegram(s);
            let html = '<div class="prof-head">' +
                '<span class="ava' + (s.has_dossier ? ' dossier' : '') + '" style="' + avaFull(s) + '">' + esc(initial(s.name)) + '</span>' +
                '<div><div class="nm">' + esc(s.name) + '</div>' + (s.city ? '<div class="city">' + esc(s.city) + '</div>' : '') + '</div></div>';
            const dossierBadge = s.has_dossier
                ? '<span class="badge ' + (VERDICT_BADGE[s.verdict] || 'gold') + '">' +
                    (VERDICT_EMOJI[s.verdict] ? VERDICT_EMOJI[s.verdict] + ' ' : '') +
                    'Досьє' + (VERDICT_WORD[s.verdict] ? ': ' + VERDICT_WORD[s.verdict] : '') + '</span>'
                : '<span class="badge">без досьє</span>';
            html += '<div class="badges">' +
                '<span class="badge">' + esc(ratingText(s)) + '</span>' +
                dossierBadge +
                (telegramMissing ? '<span class="badge red">Telegram відсутній</span>' : '') +
                '</div>';
            html += statusPanel(s);
            html += bindingPanel(s);
            html += renderHistory(s);
            if (!s.active) html += '<div class="warn-line">Профіль неактивний — публічне посилання поки не відкривається.</div>';
            html += '<div class="sub-h">Контакти</div>';
            if (s.url) html += contactRow('Профіль', s.url, s.url, 'Посилання скопійовано');
            if (s.phone) html += contactRow('Телефон', s.phone, 'tel:' + s.phone, 'Телефон скопійовано');
            html += emailEditor(s);
            html += telegramEditor(s);
            if (s.website) html += contactRow('Сайт', s.website, s.website, 'Сайт скопійовано');
            const otherSocials = (s.socials || []).filter(soc => String(soc.label || '').toLowerCase() !== 'telegram');
            if (otherSocials.length) {
                html += '<div class="sub-h">Соцмережі</div>';
                otherSocials.forEach(soc => { html += contactRow(soc.label, soc.url, soc.url, soc.label + ' скопійовано'); });
            }
            html += '<div class="contact-actions">' +
                '<button type="button" class="mini-toggle' + (telegramMissing ? ' on' : '') + '" data-toggle-no-telegram>' +
                (telegramMissing ? 'Telegram відсутній ✓' : 'Позначити: немає Telegram') +
                '</button>' +
                (telegramExists && !telegramMissing ? '<span class="count" style="align-self:center">У соцмережах знайдено Telegram.</span>' : '') +
                '</div>';
            box.innerHTML = html;
        }
        $('contacts').addEventListener('click', async e => {
            const saveEmailBtn = e.target.closest('[data-save-email]');
            if (saveEmailBtn && selectedId != null) {
                const input = $('contacts').querySelector('[data-email-input]');
                const note = $('contacts').querySelector('[data-email-note]');
                const value = (input?.value || '').trim();
                // Легка клієнтська валідація — сервер перевіряє суворо (email:rfc).
                if (value !== '' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
                    input?.classList.add('is-invalid');
                    if (note) { note.textContent = 'Некоректний email — перевірте адресу.'; note.classList.add('err'); }
                    return;
                }
                input?.classList.remove('is-invalid');
                saveEmailBtn.disabled = true;
                const prevLabel = saveEmailBtn.textContent;
                try {
                    const data = await persistContact(selectedId, { email: value });
                    if (note) { note.textContent = data.email ? 'Email збережено в базу ✓' : 'Email очищено.'; note.classList.remove('err'); }
                    saveEmailBtn.textContent = 'Збережено ✓';
                    saveEmailBtn.classList.add('is-saved');
                    toast(data.email ? 'Email збережено' : 'Email очищено');
                    setTimeout(() => { saveEmailBtn.textContent = prevLabel; saveEmailBtn.classList.remove('is-saved'); saveEmailBtn.disabled = false; }, 1600);
                } catch (err) {
                    saveEmailBtn.disabled = false;
                    const msg = err.status === 422 ? 'Некоректний email — перевірте адресу.' : 'Не вдалося зберегти email.';
                    if (note) { note.textContent = msg; note.classList.add('err'); }
                    input?.classList.add('is-invalid');
                    toast('Помилка збереження email');
                }
                return;
            }
            const saveTelegramBtn = e.target.closest('[data-save-telegram]');
            if (saveTelegramBtn && selectedId != null) {
                const input = $('contacts').querySelector('[data-telegram-input]');
                const note = $('contacts').querySelector('[data-telegram-note]');
                const value = (input?.value || '').trim();
                if (value !== '' && !/^@?[A-Za-z0-9_]{5,32}$/.test(value) && !/^(https?:\/\/)?(t\.me|telegram\.me)\//i.test(value)) {
                    input?.classList.add('is-invalid');
                    if (note) { note.textContent = 'Вкажіть @username або посилання t.me.'; note.classList.add('err'); }
                    return;
                }
                input?.classList.remove('is-invalid');
                saveTelegramBtn.disabled = true;
                const prevLabel = saveTelegramBtn.textContent;
                try {
                    const data = await persistContact(selectedId, { telegram: value });
                    if (note) { note.textContent = data.telegram ? 'Telegram збережено в базу ✓' : 'Telegram очищено.'; note.classList.remove('err'); }
                    saveTelegramBtn.textContent = 'Збережено ✓';
                    saveTelegramBtn.classList.add('is-saved');
                    renderContacts(byId[selectedId]);
                    updateCounters();
                    renderList();
                    toast(data.telegram ? 'Telegram збережено' : 'Telegram очищено');
                    setTimeout(() => { saveTelegramBtn.textContent = prevLabel; saveTelegramBtn.classList.remove('is-saved'); saveTelegramBtn.disabled = false; }, 1600);
                } catch (err) {
                    saveTelegramBtn.disabled = false;
                    const msg = err.status === 422 ? 'Вкажіть @username або посилання t.me.' : 'Не вдалося зберегти Telegram.';
                    if (note) { note.textContent = msg; note.classList.add('err'); }
                    input?.classList.add('is-invalid');
                    toast('Помилка збереження Telegram');
                }
                return;
            }
            const noTelegramBtn = e.target.closest('[data-toggle-no-telegram]');
            if (noTelegramBtn && selectedId != null) {
                const nextState = !isTelegramMissing(selectedId);
                noTelegramBtn.disabled = true;
                try {
                    await persistTelegramMissing(selectedId, nextState);
                    renderContacts(byId[selectedId]);
                    updateCounters();
                    renderList();
                    toast(isTelegramMissing(selectedId) ? 'Позначено: Telegram відсутній' : 'Позначку Telegram знято');
                } catch (err) {
                    renderContacts(byId[selectedId]);
                    updateCounters();
                    renderList();
                    toast('Не вдалося зберегти позначку');
                }
                return;
            }
            const btn = e.target.closest('.copy');
            if (btn) copy(btn.dataset.copy, btn.dataset.label);
        });
        // Enter у полях контактів → зберегти.
        $('contacts').addEventListener('keydown', e => {
            if (e.key === 'Enter' && e.target.matches('[data-email-input]')) {
                e.preventDefault();
                $('contacts').querySelector('[data-save-email]')?.click();
            }
            if (e.key === 'Enter' && e.target.matches('[data-telegram-input]')) {
                e.preventDefault();
                $('contacts').querySelector('[data-save-telegram]')?.click();
            }
        });

        renderSeqBar(); renderTabs(); updateCounters(); renderList(); regenerate();
        refreshBackendStatuses(true);
        setInterval(() => refreshBackendStatuses(true), 60000);
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) refreshBackendStatuses(true);
        });
    </script>
</body>
</html>
