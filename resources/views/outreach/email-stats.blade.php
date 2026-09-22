<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Email-статистика розсилок · Dovira</title>
    @php
        $fmt = fn ($n) => number_format((int) $n, 0, '', ' ');
        $dateFmt = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('d.m.Y H:i') : '—';
        $dayFmt = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('d.m.Y') : '—';
        $profileUrl = fn ($slug) => $slug ? rtrim($publicBase, '/') . route('profile.show', ['slug' => $slug], false) : null;
        // «v1_red» → «Перегляди · д1 🔴» (мапа кроків приходить з контролера).
        $messageChip = function (string $content) use ($stepLabels): string {
            [$stepId, $variant] = array_pad(explode('_', $content, 2), 2, '');
            $mark = \App\Http\Controllers\OutreachController::VARIANT_MARKS[$variant] ?? '';
            return trim(($stepLabels[$stepId] ?? $content) . ' ' . $mark);
        };
        $waveQuery = fn (?string $subject) => url('/rozsylka/email-stats') . '?' . http_build_query(array_filter([
            'wave' => $subject,
            'campaign' => $campaign ?: null,
            'category' => $categoryId > 0 ? $categoryId : null,
        ]));
    @endphp
    <style>
        :root { --bg:#f4f5f8; --panel:#fff; --ink:#14161c; --muted:#697184; --soft:#969eac; --line:#e7e9ef; --inset:#f8f9fc; --blue:#2f6bff; --blue-soft:#edf3ff; --green:#087f4d; --green-soft:#e8f7ef; --gold:#a96f12; --gold-soft:#fff4dd; --red:#c92a2a; --red-soft:#fff0f0; --radius:16px; --sh:0 1px 2px rgba(20,22,28,.04), 0 8px 24px rgba(20,22,28,.05); }
        * { box-sizing: border-box; }
        body { margin:0; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif; background:var(--bg); color:var(--ink); font-size:14px; line-height:1.45; }
        a { color:var(--blue); text-decoration:none; }
        .topbar { position:sticky; top:0; z-index:5; display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap; padding:15px 26px; background:rgba(244,245,248,.86); backdrop-filter:blur(12px); border-bottom:1px solid var(--line); }
        .brand { display:flex; align-items:center; gap:12px; }
        .mark { width:30px; height:30px; border-radius:9px; background:var(--ink); color:#fff; display:inline-flex; align-items:center; justify-content:center; font-weight:700; }
        h1 { margin:0; font-size:16px; }
        .sub { color:var(--soft); font-size:12px; margin-top:1px; }
        .nav { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
        .navlink, select { border:1px solid var(--line); background:#fff; border-radius:10px; padding:8px 12px; color:var(--muted); font:inherit; font-size:13px; max-width:280px; }
        .navlink:hover { color:var(--ink); box-shadow:var(--sh); }
        .page { max-width:1280px; margin:0 auto; padding:24px; }
        .kpis { display:grid; grid-template-columns:repeat(5,1fr); gap:14px; }
        @media (max-width:980px) { .kpis { grid-template-columns:repeat(2,1fr); } }
        .kpi, .card { background:var(--panel); border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--sh); }
        .kpi { padding:18px; }
        .kpi .v { font-size:30px; line-height:1.05; font-weight:750; letter-spacing:-.02em; font-variant-numeric:tabular-nums; }
        .kpi .l { margin-top:6px; color:var(--muted); }
        .kpi .s { margin-top:4px; color:var(--soft); font-size:12px; }
        .card { margin-top:18px; padding:18px; }
        h2 { margin:0 0 4px; font-size:12px; text-transform:uppercase; letter-spacing:.06em; color:var(--soft); }
        .cardsub { margin:0 0 14px; color:var(--soft); font-size:12px; }
        table { width:100%; border-collapse:collapse; }
        th, td { padding:11px 12px; border-bottom:1px solid #f0f1f5; text-align:left; vertical-align:middle; }
        th { color:var(--soft); font-size:11px; text-transform:uppercase; letter-spacing:.04em; font-weight:700; }
        tr:last-child td { border-bottom:0; }
        tbody tr:hover td { background:var(--inset); }
        tr.active td { background:var(--blue-soft); }
        .n { text-align:right; font-variant-numeric:tabular-nums; }
        .muted { color:var(--muted); }
        .soft { color:var(--soft); }
        .pill { display:inline-flex; align-items:center; gap:5px; border-radius:999px; padding:3px 9px; font-size:12px; font-weight:650; background:#f0f2f7; color:var(--muted); white-space:nowrap; }
        .pill.ok { background:var(--green-soft); color:var(--green); }
        .pill.blue { background:var(--blue-soft); color:var(--blue); }
        .pill.gold { background:var(--gold-soft); color:var(--gold); }
        .pill.red { background:var(--red-soft); color:var(--red); }
        .profile { display:flex; flex-direction:column; gap:2px; }
        .profile b { font-weight:650; }
        .wavecell { display:flex; flex-direction:column; gap:2px; }
        .wavecell b { font-weight:650; }
        .wavecell .soft { font-size:12px; }
        .chips { display:flex; flex-wrap:wrap; gap:5px; }
        .empty { padding:34px 20px; text-align:center; color:var(--muted); }
        .scroll { overflow-x:auto; }
        .reset { font-size:12px; }
    </style>
</head>
<body>
    <div class="topbar">
        <div class="brand">
            <span class="mark">D</span>
            <div>
                <h1>Email-статистика розсилок</h1>
                <div class="sub">хвилі розсилок, переходи, підтвердження й оплати</div>
            </div>
        </div>
        <div class="nav">
            <form method="get" action="{{ url('/rozsylka/email-stats') }}" style="display:flex; gap:10px; flex-wrap:wrap">
                <select name="wave" onchange="this.form.submit()" title="Розсилка">
                    <option value="">Усі розсилки</option>
                    @foreach ($byWave as $w)
                        <option value="{{ $w['subject'] }}" @selected($wave === $w['subject'])>{{ $w['label'] }} — {{ $w['subject'] }}</option>
                    @endforeach
                </select>
                <select name="category" onchange="this.form.submit()">
                    <option value="0">Усі категорії</option>
                    @foreach ($categoryOptions as $category)
                        <option value="{{ $category['id'] }}" @selected($categoryId === (int) $category['id'])>{{ $category['name'] }}</option>
                    @endforeach
                </select>
                @if ($campaign !== '')
                    <input type="hidden" name="campaign" value="{{ $campaign }}">
                @endif
            </form>
            <a class="navlink" href="{{ route('outreach.stats', array_filter(['campaign' => $campaign ?: null])) }}">Воронка</a>
            <a class="navlink" href="{{ route('outreach') }}">До розсилки</a>
        </div>
    </div>

    <div class="page">
        <div class="kpis">
            <div class="kpi">
                <div class="v">{{ $fmt($sentTotal) }}</div>
                <div class="l">Листів відправлено</div>
                <div class="s">{{ $fmt($recipientTotal) }} унікальних email</div>
            </div>
            <div class="kpi">
                <div class="v">{{ $fmt($matchedProfiles) }}</div>
                <div class="l">Профілів знайдено</div>
                <div class="s">{{ $unmatchedEmails > 0 ? $fmt($unmatchedEmails).' email без профілю' : 'усі email звʼязані' }}</div>
            </div>
            <div class="kpi">
                <div class="v">{{ $fmt($clickedProfiles) }}</div>
                <div class="l">Профілів перейшло</div>
                <div class="s">{{ $clickRate }}% від знайдених профілів</div>
            </div>
            <div class="kpi">
                <div class="v">{{ $fmt($verifiedProfiles) }}</div>
                <div class="l">Підтвердили профіль</div>
                <div class="s">{{ $fmt($claimedProfiles) }} подали заявку</div>
            </div>
            <div class="kpi">
                <div class="v">{{ $fmt($paidProfiles) }}</div>
                <div class="l">Оплатили підписку</div>
                <div class="s">серед отримувачів email</div>
            </div>
        </div>

        <div class="card">
            <h2>Розсилки</h2>
            <p class="cardsub">
                Кожен рядок — окрема хвиля (тема листа). Клік по назві фільтрує всю сторінку під цю хвилю.
                @if ($wave !== '')
                    <a class="reset" href="{{ $waveQuery(null) }}">Скинути фільтр розсилки ✕</a>
                @endif
            </p>
            <div class="scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Розсилка</th>
                            <th>Коли</th>
                            <th class="n">Листів</th>
                            <th class="n">Отримувачів</th>
                            <th class="n">Перейшло профілів</th>
                            <th class="n">CTR</th>
                            <th class="n">Переглядів</th>
                            <th class="n">Дій</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($byWave as $w)
                            <tr @class(['active' => $w['active']])>
                                <td>
                                    <span class="wavecell">
                                        <a href="{{ $waveQuery($w['active'] ? null : $w['subject']) }}"><b>{{ $w['label'] }}</b></a>
                                        <span class="soft">«{{ $w['subject'] }}»</span>
                                    </span>
                                </td>
                                <td class="muted">
                                    @if ($w['first_at'] && $dayFmt($w['first_at']) === $dayFmt($w['last_at']))
                                        {{ $dayFmt($w['first_at']) }}
                                    @else
                                        {{ $dayFmt($w['first_at']) }} — {{ $dayFmt($w['last_at']) }}
                                    @endif
                                </td>
                                <td class="n">{{ $fmt($w['sent']) }}</td>
                                <td class="n">{{ $fmt($w['recipients']) }}</td>
                                <td class="n">
                                    @if ($w['clicked'] > 0)
                                        <span class="pill ok">{{ $fmt($w['clicked']) }}</span>
                                    @else
                                        <span class="pill">0</span>
                                    @endif
                                </td>
                                <td class="n">{{ $w['recipients'] > 0 ? $w['rate'].'%' : '—' }}</td>
                                <td class="n">{{ $fmt($w['views']) }}</td>
                                <td class="n">{{ $fmt($w['actions']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="muted">Ще не було жодної розсилки.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if ($sentTotal === 0)
            <div class="card">
                <div class="empty">
                    <b>За цим фільтром листів не знайдено.</b><br>
                    Оберіть іншу розсилку або скиньте фільтри.
                </div>
            </div>
        @else
            <div class="card">
                <h2>По категоріях</h2>
                <div class="scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Категорія</th>
                                <th class="n">Листів</th>
                                <th class="n">Профілів</th>
                                <th class="n">Перейшли</th>
                                <th class="n">%</th>
                                <th class="n">Підтв.</th>
                                <th class="n">Оплат</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($perCategory as $row)
                                <tr>
                                    <td>{{ $row['category'] }}</td>
                                    <td class="n">{{ $fmt($row['sent']) }}</td>
                                    <td class="n">{{ $fmt($row['profiles']) }}</td>
                                    <td class="n">{{ $fmt($row['clicked']) }}</td>
                                    <td class="n">{{ $row['rate'] }}%</td>
                                    <td class="n">{{ $fmt($row['verified']) }}</td>
                                    <td class="n">{{ $fmt($row['paid']) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="muted">Немає профілів для цього фільтра.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <h2>Хто перейшов і що зробив</h2>
                <p class="cardsub">Переходи рахуються за UTM-мітками листів{{ $wave !== '' ? ' обраної розсилки' : '' }}. Ті, хто перейшов, — угорі.</p>
                <div class="scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Профіль</th>
                                <th>Категорія</th>
                                <th class="n">Листів</th>
                                <th>Останній лист</th>
                                <th class="n">Переходи</th>
                                <th>З якого листа</th>
                                <th>Дії</th>
                                <th>Статус</th>
                                <th>Остання активність</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($perProfile as $profile)
                                <tr>
                                    <td>
                                        <span class="profile">
                                            @if ($url = $profileUrl($profile['slug']))
                                                <a href="{{ $url }}" target="_blank" rel="noopener"><b>{{ $profile['name'] }}</b></a>
                                            @else
                                                <b>{{ $profile['name'] }}</b>
                                            @endif
                                            <span class="soft">{{ $profile['city'] ?: 'місто не вказано' }} · {{ $profile['email'] }}</span>
                                        </span>
                                    </td>
                                    <td>{{ $profile['category'] }}</td>
                                    <td class="n">{{ $fmt($profile['sent']) }}</td>
                                    <td class="muted">{{ $dateFmt($profile['last_email_at']) }}</td>
                                    <td class="n">
                                        @if ($profile['clicked'])
                                            <span class="pill ok">{{ $fmt($profile['views']) }}</span>
                                        @else
                                            <span class="pill">0</span>
                                        @endif
                                    </td>
                                    <td>
                                        <span class="chips">
                                            @forelse ($profile['messages'] as $message)
                                                <span class="pill blue">{{ $messageChip($message) }}</span>
                                            @empty
                                                <span class="soft">—</span>
                                            @endforelse
                                        </span>
                                    </td>
                                    <td>
                                        <span class="chips">
                                            @forelse ($profile['actions'] as $action)
                                                <span class="pill blue">{{ $action }}</span>
                                            @empty
                                                <span class="soft">—</span>
                                            @endforelse
                                        </span>
                                    </td>
                                    <td>
                                        <span class="chips">
                                            @if ($profile['account'] === 'verified')
                                                <span class="pill ok">підтвердив</span>
                                            @elseif ($profile['account'] === 'claimed')
                                                <span class="pill gold">заявка</span>
                                            @else
                                                <span class="pill">без заявки</span>
                                            @endif
                                            @if ($profile['paid'])
                                                <span class="pill gold">оплатив</span>
                                            @endif
                                        </span>
                                    </td>
                                    <td class="muted">{{ $dateFmt($profile['last_event_at'] ?: $profile['last_email_at']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>
</body>
</html>
