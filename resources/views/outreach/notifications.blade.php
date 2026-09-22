<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Сповіщення · Dovira</title>
    <style>
        :root {
            --bg:#f4f5f8; --panel:#fff;
            --ink:#14161c; --ink-2:#565d6b; --ink-3:#949aa6;
            --line:#e9eaef; --line-2:#f1f2f6; --inset:#f7f8fb;
            --accent:#2f6bff; --accent-600:#1f57e6; --accent-soft:#ecf1ff;
            --ok:#0a8f4f; --ok-soft:#e7f6ee;
            --warn:#b7791f; --warn-soft:#fbf2df;
            --gold:#b8860b; --gold-soft:#faf2dd;
            --red:#dc2626; --red-soft:#fee2e2;
            --r:18px; --r-sm:12px;
            --sh:0 1px 2px rgba(20,22,28,.04), 0 4px 16px rgba(20,22,28,.04);
        }
        * { box-sizing: border-box; }
        html, body { margin: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: var(--bg); color: var(--ink); font-size: 14px; line-height: 1.5; -webkit-font-smoothing: antialiased; }
        a { color: var(--accent); }

        .topbar { display: flex; align-items: center; justify-content: space-between; gap: 20px; flex-wrap: wrap;
            padding: 15px 26px; background: rgba(244,245,248,.8); backdrop-filter: saturate(1.6) blur(12px);
            border-bottom: 1px solid var(--line); position: sticky; top: 0; z-index: 20; }
        .brand { display: flex; align-items: center; gap: 12px; }
        .brand .mark { width: 30px; height: 30px; border-radius: 9px; background: var(--ink); color: #fff;
            display: inline-flex; align-items: center; justify-content: center; font-weight: 700; font-size: 15px; }
        .brand h1 { font-size: 16px; margin: 0; font-weight: 650; letter-spacing: -.02em; }
        .brand .sub { color: var(--ink-3); font-size: 12px; margin-top: 1px; }
        .nav { display: flex; align-items: center; gap: 10px; }
        .navlink { text-decoration: none; font-size: 13px; font-weight: 550; padding: 8px 14px; border-radius: 10px; color: var(--ink-2); transition: .12s; }
        .navlink:hover { background: #fff; color: var(--ink); box-shadow: var(--sh); }

        .page { max-width: 860px; margin: 0 auto; padding: 24px; }

        .summary { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 20px; }
        .scard { flex: 1; min-width: 160px; background: var(--panel); border: 1px solid var(--line); border-radius: var(--r);
            box-shadow: var(--sh); padding: 16px 18px; }
        .scard .v { font-size: 26px; font-weight: 800; letter-spacing: -.02em; font-variant-numeric: tabular-nums; }
        .scard .l { color: var(--ink-3); font-size: 12.5px; margin-top: 2px; }
        .scard.alert { background: var(--warn-soft); border-color: transparent; }
        .scard.alert .v { color: var(--warn); }
        .scard.paid .v { color: var(--ok); }

        .feed { display: flex; flex-direction: column; gap: 10px; }
        .item { display: flex; gap: 14px; background: var(--panel); border: 1px solid var(--line); border-radius: var(--r);
            box-shadow: var(--sh); padding: 15px 17px; }
        .item.action { border-color: transparent; box-shadow: 0 0 0 1.5px var(--warn), var(--sh); }
        .item .ico { flex: none; width: 40px; height: 40px; border-radius: 12px; display: inline-flex; align-items: center;
            justify-content: center; font-size: 18px; background: var(--inset); box-shadow: inset 0 0 0 1px var(--line); }
        .item.kind-claim .ico { background: var(--warn-soft); box-shadow: none; }
        .item.kind-payment .ico { background: var(--ok-soft); box-shadow: none; }
        .item .body { flex: 1; min-width: 0; }
        .item .top { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .item .title { font-weight: 700; font-size: 14px; }
        .item .badge { font-size: 10.5px; font-weight: 700; padding: 2px 8px; border-radius: 999px; text-transform: uppercase; letter-spacing: .03em;
            background: var(--line-2); color: var(--ink-2); }
        .item .badge.pending { background: var(--warn); color: #fff; }
        .item .badge.paid { background: var(--ok); color: #fff; }
        .item .badge.reversed, .item .badge.failed { background: var(--red-soft); color: var(--red); }
        .item .when { margin-left: auto; font-size: 11.5px; color: var(--ink-3); white-space: nowrap; }
        .item .who { margin-top: 6px; font-size: 13px; }
        .item .who b { font-weight: 650; }
        .item .who a { text-decoration: none; }
        .item .meta { margin-top: 4px; font-size: 12.5px; color: var(--ink-2); }
        .item .meta .amount { font-weight: 700; color: var(--ok); }
        .item .prof { margin-top: 8px; display: inline-flex; align-items: center; gap: 7px; font-size: 12.5px;
            background: var(--inset); border-radius: 999px; padding: 5px 12px; text-decoration: none; color: var(--ink-2); }
        .item .prof:hover { color: var(--accent); }
        .item .note { margin-top: 8px; font-size: 12.5px; color: var(--ink-2); background: var(--inset); border-radius: 10px; padding: 8px 11px; }

        .empty { text-align: center; color: var(--ink-3); padding: 60px 20px; background: var(--panel);
            border: 1px dashed var(--line); border-radius: var(--r); }
    </style>
</head>
<body>
    <div class="topbar">
        <div class="brand">
            <span class="mark">D</span>
            <div>
                <h1>Сповіщення</h1>
                <div class="sub">заявки на підтвердження прав та оплати PRO</div>
            </div>
        </div>
        <div class="nav">
            <a class="navlink" href="{{ route('outreach') }}">← до розсилки</a>
            <a class="navlink" href="{{ route('outreach.stats') }}">Статистика</a>
        </div>
    </div>

    <div class="page">
        <div class="summary">
            <div class="scard {{ $pendingClaims > 0 ? 'alert' : '' }}">
                <div class="v">{{ $pendingClaims }}</div>
                <div class="l">Заявок очікують розгляду</div>
            </div>
            <div class="scard paid">
                <div class="v">{{ $paidCount }}</div>
                <div class="l">Оплат PRO усього</div>
            </div>
            @if ($pendingPayments > 0)
                <div class="scard">
                    <div class="v">{{ $pendingPayments }}</div>
                    <div class="l">Платежів у процесі</div>
                </div>
            @endif
        </div>

        @if (empty($feed))
            <div class="empty">Поки що порожньо. Тут зʼявлятимуться заявки на підтвердження прав і оплати PRO.</div>
        @else
            <div class="feed">
                @foreach ($feed as $n)
                    <div class="item kind-{{ $n['kind'] }} {{ $n['needs_action'] ? 'action' : '' }}">
                        <span class="ico">{{ $n['kind'] === 'claim' ? '🪪' : '₴' }}</span>
                        <div class="body">
                            <div class="top">
                                <span class="title">{{ $n['title'] }}</span>
                                @if ($n['kind'] === 'claim')
                                    <span class="badge {{ $n['status'] === 'pending' ? 'pending' : $n['status'] }}">{{ ['pending' => 'очікує', 'approved' => 'схвалено', 'rejected' => 'відхилено'][$n['status']] ?? $n['status'] }}</span>
                                @else
                                    <span class="badge {{ $n['status'] }}">{{ ['paid' => 'оплачено', 'pending' => 'у процесі', 'reversed' => 'повернено', 'failed' => 'невдало'][$n['status']] ?? $n['status'] }}</span>
                                @endif
                                <span class="when">{{ $n['at'] }}</span>
                            </div>
                            <div class="who">
                                <b>{{ $n['user_name'] }}</b>@if (!empty($n['user_email'])) · <a href="mailto:{{ $n['user_email'] }}">{{ $n['user_email'] }}</a>@endif
                            </div>
                            @if ($n['kind'] === 'claim' && !empty($n['role']))
                                <div class="meta">Роль: {{ $n['role'] }}</div>
                            @endif
                            @if ($n['kind'] === 'payment' && !empty($n['amount']))
                                <div class="meta"><span class="amount">{{ $n['amount'] }}</span></div>
                            @endif
                            @if (!empty($n['profile_url']))
                                <a class="prof" href="{{ $n['profile_url'] }}" target="_blank" rel="noopener">
                                    🏛 {{ $n['profile_name'] }}@if (!empty($n['city'])) · {{ $n['city'] }}@endif
                                </a>
                            @else
                                <div class="prof">🏛 {{ $n['profile_name'] }}@if (!empty($n['city'])) · {{ $n['city'] }}@endif</div>
                            @endif
                            @if (!empty($n['note']))
                                <div class="note">{{ $n['note'] }}</div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</body>
</html>
