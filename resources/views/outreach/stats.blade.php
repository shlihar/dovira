<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Статистика розсилки · Dovira</title>
    @php
        $fmt = fn ($n) => number_format((int) $n, 0, '', ' ');
        $hasData = $lawyersOpened > 0 || $clicksTotal > 0;
        $best = collect($perMessage)->where('opened', '>', 0)->sortByDesc('rate')->first();
        $maxEventCount = collect($byEvent)->max('count') ?: 1;
        $stageMeta = [
            'viewed'       => ['Лише переглянув', 'neutral'],
            'info'         => ['Відкрив «Інформація»', 'neutral'],
            'dossier_open' => ['Натиснув досьє', 'info'],
            'dossier_view' => ['Переглянув досьє', 'info'],
            'contacted'    => ['✓ Клікнув контакт', 'good'],
        ];
        // Зведення воронки монетизації по всіх адвокатах, що переходили.
        $accVerified = collect($perLawyer)->where('account', 'verified')->count();
        $accClaimed = collect($perLawyer)->where('account', 'claimed')->count();
        $paidCount = collect($perLawyer)->where('paid', true)->count();
        // Посилання на профілі ведуть на живий сайт, а не на локальний APP_URL.
        $publicBase = (string) config('outreach.public_url', 'https://mydovira.com');
    @endphp
    <style>
        :root {
            --bg:#f4f5f8; --panel:#fff;
            --ink:#14161c; --ink-2:#565d6b; --ink-3:#949aa6;
            --line:#e9eaef; --line-2:#f1f2f6; --inset:#f7f8fb;
            --accent:#2f6bff; --accent-600:#1f57e6; --accent-soft:#ecf1ff;
            --track:#eef0f4;
            --ok:#0a8f4f; --ok-soft:#e7f6ee;
            --warn:#b7791f; --warn-soft:#fbf2df;
            --gold:#b8860b; --gold-soft:#faf2dd;
            --radius:18px;
            --sh:0 1px 2px rgba(20,22,28,.04), 0 4px 16px rgba(20,22,28,.04);
        }
        * { box-sizing: border-box; }
        html, body { margin: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: var(--bg); color: var(--ink); font-size: 14px; line-height: 1.5; -webkit-font-smoothing: antialiased; }
        a { color: var(--accent); }

        .topbar { display: flex; align-items: center; justify-content: space-between; gap: 20px; flex-wrap: wrap;
            padding: 15px 26px; background: rgba(244,245,248,.8); backdrop-filter: saturate(1.6) blur(12px);
            border-bottom: 1px solid var(--line); position: sticky; top: 0; z-index: 10; }
        .topbar .brand { display: flex; align-items: center; gap: 12px; }
        .topbar .brand .mark { width: 30px; height: 30px; border-radius: 9px; background: var(--ink); color: #fff;
            display: inline-flex; align-items: center; justify-content: center; font-weight: 700; font-size: 15px; }
        .topbar h1 { font-size: 16px; margin: 0; font-weight: 650; letter-spacing: -.02em; }
        .topbar .sub { color: var(--ink-3); font-size: 12px; margin-top: 1px; }
        .topbar select { font: inherit; padding: 8px 12px; border: 1px solid var(--line); border-radius: 10px; background: #fff; color: var(--ink); }
        .navlink { text-decoration: none; font-size: 13px; font-weight: 550; padding: 8px 14px; border-radius: 10px; color: var(--ink-2); transition: .12s; }
        .navlink:hover { background: #fff; color: var(--ink); box-shadow: var(--sh); }

        .page { max-width: 1180px; margin: 0 auto; padding: 24px; }
        .card { background: var(--panel); border: 1px solid var(--line); border-radius: var(--radius); padding: 20px; box-shadow: var(--sh); }
        .card + .card, .grid + .card, .card + .grid { margin-top: 20px; }
        h2 { font-size: 12px; margin: 0 0 16px; font-weight: 650; text-transform: uppercase; letter-spacing: .06em; color: var(--ink-3); }
        .muted { color: var(--ink-2); }
        .num { font-variant-numeric: tabular-nums; }

        /* KPI */
        .kpis { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; }
        @media (max-width: 820px) { .kpis { grid-template-columns: repeat(2, 1fr); } }
        .kpi { background: var(--panel); border: 1px solid var(--line); border-radius: var(--radius); padding: 18px; box-shadow: var(--sh); }
        .kpi .v { font-size: 32px; font-weight: 700; line-height: 1.05; letter-spacing: -.02em; }
        .kpi .l { color: var(--ink-2); font-size: 13px; margin-top: 6px; }
        .kpi .s { color: var(--ink-3); font-size: 12px; margin-top: 6px; }
        .kpi .v small { font-size: 17px; font-weight: 600; color: var(--ink-3); }

        /* Воронка монетизації (перейшов → акаунт → оплата) */
        .money { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0; border: 1px solid var(--line); border-radius: var(--radius); overflow: hidden; background: var(--panel); box-shadow: var(--sh); }
        @media (max-width: 620px) { .money { grid-template-columns: 1fr; } }
        .money .step { padding: 18px 20px; position: relative; }
        .money .step + .step { border-left: 1px solid var(--line-2); }
        @media (max-width: 620px) { .money .step + .step { border-left: 0; border-top: 1px solid var(--line-2); } }
        .money .ico { width: 30px; height: 30px; border-radius: 9px; display: inline-flex; align-items: center; justify-content: center; font-size: 15px; margin-bottom: 10px; }
        .money .ico.link { background: var(--accent-soft); color: var(--accent); }
        .money .ico.acc { background: var(--ok-soft); color: var(--ok); }
        .money .ico.paid { background: var(--gold-soft); color: var(--gold); }
        .money .v { font-size: 26px; font-weight: 700; letter-spacing: -.02em; }
        .money .l { color: var(--ink-2); font-size: 13px; margin-top: 2px; }
        .money .s { color: var(--ink-3); font-size: 12px; margin-top: 4px; }

        /* Empty */
        .empty { text-align: center; padding: 36px 22px; color: var(--ink-2); }
        .empty b { color: var(--ink); display: block; font-size: 15px; margin-bottom: 8px; }

        /* Funnel */
        .funnel-row { display: grid; grid-template-columns: 200px 1fr 120px; align-items: center; gap: 16px; margin: 12px 0; }
        @media (max-width: 700px) { .funnel-row { grid-template-columns: 130px 1fr 88px; } }
        .funnel-row .lab { font-weight: 550; }
        .track { background: var(--track); border-radius: 7px; height: 28px; overflow: hidden; }
        .fill { height: 100%; background: var(--accent); border-radius: 7px; min-width: 3px; transition: width .3s; }
        .funnel-row .val { text-align: right; }
        .funnel-row .val b { font-size: 16px; }
        .funnel-row .val span { color: var(--ink-3); font-size: 12px; }
        .dropnote { color: var(--ink-2); font-size: 13px; margin-top: 14px; }

        /* Tables */
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 11px 12px; border-bottom: 1px solid var(--line-2); vertical-align: middle; }
        th { color: var(--ink-3); font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; }
        tbody tr:last-child td { border-bottom: 0; }
        tbody tr:hover td { background: var(--line-2); }
        td.n, th.n { text-align: right; font-variant-numeric: tabular-nums; }
        tr.best td { background: var(--accent-soft); }
        tr.best:hover td { background: #e4ecfe; }
        .minibar { display: inline-block; vertical-align: middle; width: 90px; height: 8px; border-radius: 4px; background: var(--track); margin-right: 8px; overflow: hidden; }
        .minibar > i { display: block; height: 100%; background: var(--accent); border-radius: 4px; }
        .chip { display: inline-block; font-size: 12px; font-weight: 550; background: var(--line-2); color: var(--ink); border-radius: 7px; padding: 2px 8px; margin: 1px 2px 1px 0; }
        .tag { display: inline-flex; align-items: center; gap: 5px; font-size: 12px; font-weight: 600; border-radius: 999px; padding: 2px 10px; white-space: nowrap; }
        .tag.good { background: var(--ok-soft); color: var(--ok); }
        .tag.neutral { background: var(--line-2); color: var(--ink-2); }
        .tag.info { background: var(--accent-soft); color: var(--accent); }

        /* Сигнали воронки в таблиці */
        .sig { display: inline-flex; gap: 4px; }
        .dot { width: 18px; height: 18px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center;
            font-size: 10px; font-weight: 700; background: var(--line-2); color: var(--ink-3); line-height: 1; }
        .dot.on-link { background: var(--accent-soft); color: var(--accent); }
        .dot.on-acc { background: var(--ok-soft); color: var(--ok); }
        .dot.on-acc-partial { background: var(--warn-soft); color: var(--warn); }
        .dot.on-paid { background: var(--gold-soft); color: var(--gold); }

        /* horizontal bar list */
        .hbar { display: grid; grid-template-columns: 130px 1fr 60px; align-items: center; gap: 14px; margin: 10px 0; }
        .hbar .lab { font-size: 13px; }
        .hbar .val { text-align: right; font-variant-numeric: tabular-nums; font-weight: 600; }

        .legend { display: flex; flex-wrap: wrap; gap: 10px 18px; margin-top: 14px; font-size: 11.5px; color: var(--ink-2); }
        .legend span { display: inline-flex; align-items: center; gap: 6px; }

        /* Монограма-аватар (як у розсилці) */
        .lw { display: flex; align-items: center; gap: 11px; }
        .ava { flex: none; width: 34px; height: 34px; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center;
            font-weight: 650; font-size: 14px; }
    </style>
    @php
        $initial = function ($name) {
            preg_match('/\p{L}|\p{N}/u', (string) $name, $m);
            return $m ? mb_strtoupper($m[0]) : '—';
        };
        $avaStyle = function ($name) {
            $h = 0;
            foreach (mb_str_split((string) $name) as $ch) { $h = ($h * 31 + mb_ord($ch)) % 360; }
            $h = abs($h);
            return "background:hsl({$h} 52% 94%);color:hsl({$h} 42% 40%)";
        };
    @endphp
</head>
<body>
    <div class="topbar">
        <div class="brand">
            <span class="mark">D</span>
            <div>
                <h1>Статистика розсилки</h1>
                <span class="sub">хто перейшов, що робив, підтвердив акаунт і оплатив</span>
            </div>
        </div>
        <div style="display:flex; align-items:center; gap:12px;">
            <form method="get" action="{{ route('outreach.stats') }}">
                <select name="campaign" onchange="this.form.submit()">
                    <option value="">Усі кампанії</option>
                    @foreach ($campaigns as $c)
                        <option value="{{ $c }}" @selected($campaign === $c)>{{ $c }}</option>
                    @endforeach
                </select>
            </form>
            <a class="navlink" href="{{ route('outreach') }}">← до розсилки</a>
        </div>
    </div>

    <div class="page">
        {{-- KPI --}}
        <div class="kpis">
            <div class="kpi">
                <div class="v num">{{ $fmt($lawyersOpened) }}</div>
                <div class="l">Адвокатів перейшло</div>
                <div class="s">{{ $fmt($viewsTotal) }} переглядів усього</div>
            </div>
            <div class="kpi">
                <div class="v num">{{ $fmt($lawyersEngaged) }}</div>
                <div class="l">Клікнули контакт</div>
                <div class="s">{{ $fmt($clicksTotal) }} кліків усього</div>
            </div>
            <div class="kpi">
                <div class="v num">{{ $engageRate }}<small>%</small></div>
                <div class="l">Engagement</div>
                <div class="s">клікнули / перейшли</div>
            </div>
            <div class="kpi">
                <div class="v" style="font-size:20px; padding-top:6px">{{ $best ? $best['label'] : '—' }}</div>
                <div class="l">Найкраще повідомлення</div>
                <div class="s">{{ $best ? $best['rate'].'% engagement' : 'даних ще немає' }}</div>
            </div>
        </div>

        @unless ($hasData)
            <div class="card">
                <div class="empty">
                    <b>Ще немає переходів за посиланнями.</b>
                    Щойно почнете розсилати повідомлення з <a href="{{ route('outreach') }}">/rozsylka</a> — тут зʼявиться воронка:
                    хто перейшов на профіль, що робив, підтвердив акаунт і оплатив PRO.
                    Дані читаються з подій профілю за UTM-міткою <code>utm_source=outreach</code>.
                </div>
            </div>
        @else
            {{-- Воронка монетизації: перейшов → акаунт → оплата --}}
            <div class="money">
                <div class="step">
                    <div class="ico link">↗</div>
                    <div class="v num">{{ $fmt($lawyersOpened) }}</div>
                    <div class="l">Перейшли по посиланню</div>
                    <div class="s">з нашої розсилки</div>
                </div>
                <div class="step">
                    <div class="ico acc">✓</div>
                    <div class="v num">{{ $fmt($accVerified) }}<span style="font-size:15px;color:var(--ink-3);font-weight:600"> +{{ $fmt($accClaimed) }}</span></div>
                    <div class="l">Підтвердили акаунт</div>
                    <div class="s">{{ $fmt($accClaimed) }} подали заявку</div>
                </div>
                <div class="step">
                    <div class="ico paid">₴</div>
                    <div class="v num">{{ $fmt($paidCount) }}</div>
                    <div class="l">Оплатили PRO</div>
                    <div class="s">з тих, хто переходив</div>
                </div>
            </div>

            {{-- Воронка досьє --}}
            <div class="card">
                <h2>Що робили після переходу з розсилки</h2>
                @foreach ($funnel as $stage)
                    <div class="funnel-row">
                        <span class="lab">{{ $stage['label'] }}</span>
                        <span class="track"><span class="fill" style="width: {{ $stage['pct'] }}%"></span></span>
                        <span class="val"><b class="num">{{ $fmt($stage['count']) }}</b> <span>{{ $stage['pct'] }}%</span></span>
                    </div>
                @endforeach
                <p class="dropnote">
                    Кожен етап — скільки різних адвокатів його досягли (% від тих, хто перейшов).
                    Переглянули профіль, але не клікнули контакт: <b class="num">{{ $fmt($lawyersOpened - $lawyersEngaged) }}</b>.
                </p>
            </div>

            {{-- Порівняння повідомлень --}}
            <div class="card">
                <h2>Яке повідомлення краще</h2>
                <div style="overflow-x:auto">
                    <table>
                        <thead>
                            <tr>
                                <th>Повідомлення</th>
                                <th class="n">Перейшло</th>
                                <th class="n">Переглядів</th>
                                <th class="n">Клікнули</th>
                                <th class="n">Кліків</th>
                                <th>Engagement</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($perMessage as $m)
                                <tr class="{{ $best && $m['id'] === $best['id'] && $m['opened'] > 0 ? 'best' : '' }}">
                                    <td><b>{{ $m['label'] }}</b> <span class="muted">{{ $m['id'] }}</span></td>
                                    <td class="n">{{ $fmt($m['opened']) }}</td>
                                    <td class="n">{{ $fmt($m['views']) }}</td>
                                    <td class="n">{{ $fmt($m['engaged']) }}</td>
                                    <td class="n">{{ $fmt($m['clicks']) }}</td>
                                    <td>
                                        <span class="minibar"><i style="width: {{ $m['rate'] }}%"></i></span>
                                        <span class="num">{{ $m['rate'] }}%</span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="dropnote">Engagement = скільки адвокатів клікнули контакт із тих, хто перейшов. Порівнюйте при схожій кількості надісланих.</p>
            </div>

            {{-- Що робили --}}
            @if (count($byEvent))
                <div class="card">
                    <h2>Що робили на профілі</h2>
                    @foreach ($byEvent as $e)
                        <div class="hbar">
                            <span class="lab">{{ $e['label'] }}</span>
                            <span class="track"><span class="fill" style="width: {{ round($e['count'] / $maxEventCount * 100) }}%"></span></span>
                            <span class="val num">{{ $fmt($e['count']) }}</span>
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- По адвокатах --}}
            <div class="card">
                <h2>По адвокатах <span class="muted" style="font-weight:400;text-transform:none;letter-spacing:0">(останні активні{{ count($perLawyer) >= 300 ? ', показано 300' : '' }})</span></h2>
                <div style="overflow-x:auto">
                    <table>
                        <thead>
                            <tr>
                                <th>Адвокат</th>
                                <th>Статус</th>
                                <th>Повідомлення</th>
                                <th class="n">Переглядів</th>
                                <th>Дії</th>
                                <th>Етап</th>
                                <th>Коли</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($perLawyer as $l)
                                @php
                                    $accCls = $l['account'] === 'verified' ? 'on-acc' : ($l['account'] === 'claimed' ? 'on-acc-partial' : '');
                                    $accTitle = $l['account'] === 'verified' ? 'Підтвердив акаунт' : ($l['account'] === 'claimed' ? 'Подав заявку на акаунт' : 'Акаунт не заявляв');
                                @endphp
                                <tr>
                                    <td>
                                        <div class="lw">
                                            <span class="ava" style="{{ $avaStyle($l['name']) }}">{{ $initial($l['name']) }}</span>
                                            <div>
                                                @if ($l['slug'])
                                                    <a href="{{ $publicBase . route('profile.show', ['slug' => $l['slug']], false) }}" target="_blank" rel="noopener">{{ $l['name'] }}</a>
                                                @else
                                                    {{ $l['name'] }}
                                                @endif
                                                @if ($l['city'])<div class="muted" style="font-size:12px">{{ $l['city'] }}</div>@endif
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="sig">
                                            <i class="dot {{ $l['clicked'] ? 'on-link' : '' }}" title="{{ $l['clicked'] ? 'Перейшов по посиланню' : 'Не переходив' }}">↗</i>
                                            <i class="dot {{ $accCls }}" title="{{ $accTitle }}">{{ $l['account'] === 'claimed' ? '~' : '✓' }}</i>
                                            <i class="dot {{ $l['paid'] ? 'on-paid' : '' }}" title="{{ $l['paid'] ? 'Оплатив PRO' : 'Не оплачував' }}">₴</i>
                                        </span>
                                    </td>
                                    <td>@foreach ($l['messages'] as $mid)<span class="chip">{{ $mid }}</span>@endforeach</td>
                                    <td class="n">{{ $fmt($l['views']) }}</td>
                                    <td>
                                        @forelse ($l['actions'] as $a)<span class="chip">{{ $a }}</span>@empty<span class="muted">—</span>@endforelse
                                    </td>
                                    <td>
                                        @php ($sm = $stageMeta[$l['stage']] ?? ['—', 'neutral'])
                                        <span class="tag {{ $sm[1] }}">{{ $sm[0] }}</span>
                                    </td>
                                    <td class="muted" style="font-size:12px">{{ $l['last_at'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="legend">
                    <span><i class="dot on-link">↗</i> перейшов по посиланню</span>
                    <span><i class="dot on-acc">✓</i> підтвердив акаунт</span>
                    <span><i class="dot on-acc-partial">~</i> подав заявку</span>
                    <span><i class="dot on-paid">₴</i> оплатив PRO</span>
                </div>
            </div>
        @endunless
    </div>
</body>
</html>
