import { initSearchForms } from './search.js';
import { initAccountSidebarMenu, initProfileVisualSeeds } from './profile-ui.js';

const PRO_ACCOUNT_PATH = '/pro/account';

let chartJsLoader = null;
let proAccountNavigationController = null;

// Хуки активного воркспейсу форми профілю: перевірка незбережених змін і
// примусове збереження перед навігацією/перезавантаженням shell.
let proProfileHasPendingChanges = null;
let proProfileFlushPending = null;
let proProfileUnloadGuardBound = false;
let proAccountPopstateBound = false;
let proAccountLivewireHooksBound = false;
let proAccountClientTabsPopstateBound = false;
let pendingProAccountScrollId = null;

const isElement = (value) => value instanceof Element || value instanceof Document;

const collectNodes = (root, selector) => {
    if (!isElement(root)) return [];

    const nodes = [];

    if (root instanceof Element && root.matches(selector)) {
        nodes.push(root);
    }

    root.querySelectorAll(selector).forEach((node) => nodes.push(node));

    return nodes;
};

const loadChartJs = () => {
    if (window.Chart) {
        return Promise.resolve(window.Chart);
    }

    if (chartJsLoader) {
        return chartJsLoader;
    }

    const existingScript = document.querySelector('script[data-pro-chartjs-loader]');
    if (existingScript) {
        chartJsLoader = new Promise((resolve, reject) => {
            existingScript.addEventListener('load', () => resolve(window.Chart), { once: true });
            existingScript.addEventListener('error', reject, { once: true });
        });

        return chartJsLoader;
    }

    chartJsLoader = new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = '/static/vendor/chart.umd.min.js';
        script.async = true;
        script.dataset.proChartjsLoader = 'true';
        script.onload = () => resolve(window.Chart);
        script.onerror = reject;
        document.head.appendChild(script);
    });

    return chartJsLoader;
};

const parseJsonScript = (root, selector, fallback) => {
    const node = root.querySelector(selector);

    try {
        return JSON.parse(node?.textContent || JSON.stringify(fallback));
    } catch (error) {
        return fallback;
    }
};

const readChartPayload = (chartCanvas) => {
    try {
        return JSON.parse(chartCanvas.dataset.chart || '{}');
    } catch (error) {
        return {};
    }
};

const setProLoadingState = (node, isLoading) => {
    if (!node) return;

    node.classList.toggle('is-loading', Boolean(isLoading));
    node.setAttribute('aria-busy', isLoading ? 'true' : 'false');
};

const formatDay = (date) => new Intl.DateTimeFormat('uk-UA', { day: 'numeric' }).format(date);

const formatDayLong = (date) => new Intl.DateTimeFormat('uk-UA', {
    day: 'numeric',
    month: 'long',
}).format(date);

const resolveGroupSize = (period, valuesLength) => {
    if (period === 'last_90') return 7;
    if (period === 'last_30') return valuesLength > 18 ? 2 : 1;
    return 1;
};

const aggregateSeries = (payload) => {
    const startDate = new Date(payload.startDate);
    const values = Array.isArray(payload.values) ? payload.values.map((value) => Number(value || 0)) : [];
    const groupSize = resolveGroupSize(payload.period, values.length);
    const points = [];

    for (let index = 0; index < values.length; index += groupSize) {
        const slice = values.slice(index, index + groupSize);
        const bucketStart = new Date(startDate);
        bucketStart.setDate(startDate.getDate() + index);
        const bucketEnd = new Date(bucketStart);
        bucketEnd.setDate(bucketStart.getDate() + slice.length - 1);

        points.push({
            label: formatDay(bucketStart),
            tooltipLabel: slice.length > 1 ? `${formatDayLong(bucketStart)} - ${formatDayLong(bucketEnd)}` : formatDayLong(bucketStart),
            value: slice.reduce((sum, current) => sum + current, 0),
        });
    }

    return points;
};

const hexToRgba = (hex, alpha) => {
    const normalized = String(hex || '#2f6df6').replace('#', '');
    const value = normalized.length === 3
        ? normalized.split('').map((item) => item + item).join('')
        : normalized.padEnd(6, '0').slice(0, 6);
    const numeric = Number.parseInt(value, 16);
    const red = (numeric >> 16) & 255;
    const green = (numeric >> 8) & 255;
    const blue = numeric & 255;

    return `rgba(${red}, ${green}, ${blue}, ${alpha})`;
};

const isNodeVisible = (node) => {
    if (!node) return false;

    const rect = node.getBoundingClientRect();
    const style = window.getComputedStyle(node);

    return !node.hidden && style.display !== 'none' && style.visibility !== 'hidden' && rect.width > 0 && rect.height > 0;
};

export const initProHeader = () => {
    if (!document.body.classList.contains('page-pro')) return;

    const header = document.querySelector('[data-header]');
    if (!header || header.dataset.proHeaderBound === 'true') return;

    header.dataset.proHeaderBound = 'true';

    const syncHeaderState = () => {
        header.classList.toggle('is-scrolled', window.scrollY > 24);
    };

    syncHeaderState();
    window.addEventListener('scroll', syncHeaderState, { passive: true });
};

export const initProPricing = () => {
    if (!document.body.classList.contains('page-pro')) return;

    const switcher = document.querySelector('.pro-pricing__switch');
    if (!switcher || switcher.dataset.proPricingBound === 'true') return;

    const toggles = Array.from(document.querySelectorAll('[data-billing-toggle]'));
    const amounts = Array.from(document.querySelectorAll('[data-price-amount]'));
    const periods = Array.from(document.querySelectorAll('[data-price-period]'));

    if (!toggles.length || !amounts.length || !periods.length) return;

    switcher.dataset.proPricingBound = 'true';

    const formatPrice = (value) => Number(value || 0).toLocaleString('uk-UA');

    const setBilling = (mode, animate = true) => {
        toggles.forEach((toggle) => {
            const isActive = toggle.dataset.billingToggle === mode;
            toggle.classList.toggle('is-active', isActive);
            toggle.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });

        switcher.classList.toggle('is-yearly', mode === 'yearly');

        const priceRows = document.querySelectorAll('.pro-price-card__price');
        if (animate) {
            priceRows.forEach((row) => row.classList.add('is-updating'));
        }

        const commit = () => {
            amounts.forEach((amount) => {
                const value = mode === 'yearly' ? amount.dataset.yearly : amount.dataset.monthly;
                amount.textContent = formatPrice(value);
            });

            periods.forEach((period) => {
                const value = mode === 'yearly' ? period.dataset.yearly : period.dataset.monthly;
                period.textContent = value || '';
            });

            if (animate) {
                requestAnimationFrame(() => {
                    priceRows.forEach((row) => row.classList.remove('is-updating'));
                });
            }
        };

        if (!animate) {
            commit();
            return;
        }

        window.setTimeout(commit, 130);
    };

    toggles.forEach((toggle) => {
        toggle.addEventListener('click', () => {
            setBilling(toggle.dataset.billingToggle || 'monthly', true);
        });
    });

    setBilling('monthly', false);
};

// Chart.js responsive auto-size can miss when a chart is (re)created inside a
// Livewire morph on mobile: the canvas backing store stays at the default
// 300×150 and the chart paints blank even though the container is sized right.
// One requestAnimationFrame was flaky (it sometimes fired before layout
// settled), so re-measure across a double rAF plus a short fallback, and only
// when the container actually has a non-zero box.
// Chart.js responsive auto-size can miss when a chart is (re)created inside a
// Livewire morph on mobile: the canvas backing store stays at the default
// 300×150 and the chart paints blank even though the container is sized right.
// A manual resize of the LIVE chart fixes it, so poll for ~2.5s and resize the
// chart currently registered on the canvas whenever its backing store has
// drifted from the real container size.
const resizeChartToContainer = (canvas) => {
    try {
        const chart = window.Chart?.getChart?.(canvas);
        const rect = canvas?.parentElement?.getBoundingClientRect();
        if (!chart || !rect || rect.width <= 0 || rect.height <= 0) return;

        const dpr = window.devicePixelRatio || 1;
        const expectedWidth = Math.floor(rect.width * dpr);
        if (Math.abs(canvas.width - expectedWidth) > 2) {
            chart.resize();
        }
    } catch (_) {
        // Chart was destroyed by a newer render — safe to skip.
    }
};

const ensureChartSized = (chart, canvas) => {
    let attempts = 0;
    const tick = () => {
        attempts += 1;
        resizeChartToContainer(canvas);
        if (attempts < 18) {
            window.setTimeout(tick, attempts < 3 ? 60 : 150);
        }
    };
    window.requestAnimationFrame(tick);
};

const renderOverviewChart = (analyticsCard, chartCanvas, payload) => {
    const points = aggregateSeries(payload);
    const labels = points.map((point) => point.label);
    const values = points.map((point) => point.value);

    return loadChartJs().then(() => {
        const context = chartCanvas.getContext('2d');
        if (!context || !window.Chart) return;

        if (analyticsCard._proChartInstance) {
            analyticsCard._proChartInstance.destroy();
        }

        const gradient = context.createLinearGradient(0, 0, 0, chartCanvas.clientHeight || 324);
        gradient.addColorStop(0, 'rgba(47, 109, 246, 0.24)');
        gradient.addColorStop(1, 'rgba(47, 109, 246, 0.02)');

        const highlightIndex = values.reduce((bestIndex, value, index, all) => {
            if (value > (all[bestIndex] ?? -1)) {
                return index;
            }

            return bestIndex;
        }, 0);

        const labelStep = labels.length <= 4 ? 1 : Math.ceil((labels.length - 1) / 3);

        const crosshairPlugin = {
            id: 'proCrosshair',
            afterDraw(chart) {
                const active = chart.tooltip?.getActiveElements?.() || [];
                if (!active.length) return;
                const x = active[0].element.x;
                const { top, bottom } = chart.chartArea;
                const g = chart.ctx;
                g.save();
                g.beginPath();
                g.setLineDash([4, 4]);
                g.strokeStyle = 'rgba(47, 109, 246, 0.35)';
                g.lineWidth = 1;
                g.moveTo(x, top);
                g.lineTo(x, bottom);
                g.stroke();
                g.restore();
            },
        };

        analyticsCard._proChartInstance = new window.Chart(context, {
            type: 'line',
            plugins: [crosshairPlugin],
            data: {
                labels,
                datasets: [{
                    data: values,
                    borderColor: '#2f6df6',
                    backgroundColor: gradient,
                    fill: true,
                    borderWidth: 2.5,
                    borderCapStyle: 'round',
                    tension: 0.42,
                    pointRadius: (pointContext) => {
                        const index = pointContext.dataIndex ?? 0;
                        return index === highlightIndex && values[index] > 0 ? 3.5 : 0;
                    },
                    pointHoverRadius: 5,
                    pointBorderWidth: 3,
                    pointBackgroundColor: '#2f6df6',
                    pointBorderColor: '#ffffff',
                    pointHitRadius: 18,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: {
                    duration: 360,
                    easing: 'easeOutQuart',
                },
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                plugins: {
                    legend: {
                        display: false,
                    },
                    tooltip: {
                        displayColors: false,
                        backgroundColor: '#ffffff',
                        titleColor: '#6f86af',
                        bodyColor: '#1e4fb7',
                        bodyFont: {
                            size: 14,
                            weight: '700',
                        },
                        titleFont: {
                            size: 12,
                            weight: '700',
                        },
                        borderColor: '#d8e4fb',
                        borderWidth: 1,
                        padding: 12,
                        cornerRadius: 14,
                        caretPadding: 10,
                        bodySpacing: 4,
                        callbacks: {
                            title(items) {
                                const point = points[items[0]?.dataIndex ?? 0];
                                return point?.tooltipLabel || '';
                            },
                            label(item) {
                                return `Перегляди: ${Number(item.raw || 0).toLocaleString('uk-UA')}`;
                            },
                        },
                    },
                },
                layout: {
                    padding: {
                        top: 6,
                        right: 8,
                        bottom: 0,
                        left: 0,
                    },
                },
                scales: {
                    x: {
                        border: {
                            display: false,
                        },
                        grid: {
                            display: false,
                            drawBorder: false,
                        },
                        ticks: {
                            color: '#7b8fb6',
                            font: {
                                size: 10,
                                weight: '500',
                            },
                            maxRotation: 0,
                            autoSkip: false,
                            padding: 10,
                            callback(value, index) {
                                if (index === 0 || index === labels.length - 1 || index % labelStep === 0) {
                                    return labels[index];
                                }

                                return '';
                            },
                        },
                    },
                    y: {
                        beginAtZero: true,
                        grace: '8%',
                        border: {
                            display: false,
                        },
                        grid: {
                            color: '#e7eefc',
                            drawBorder: false,
                        },
                        ticks: {
                            precision: 0,
                            color: '#7f95bc',
                            font: {
                                size: 12,
                                weight: '600',
                            },
                            padding: 10,
                            maxTicksLimit: 5,
                        },
                    },
                },
            },
        });

        ensureChartSized(analyticsCard._proChartInstance, chartCanvas);
    }).catch(() => {
        chartCanvas.closest('.pro-overview-chart')?.classList.add('has-chart-error');
    });
};

const renderProAnalyticsChart = (chartCanvas) => {
    if (!chartCanvas || !isNodeVisible(chartCanvas)) return Promise.resolve();

    const payload = readChartPayload(chartCanvas);
    const labels = Array.isArray(payload.labels) ? payload.labels : [];
    const datasets = Array.isArray(payload.datasets) ? payload.datasets : [];

    if (!labels.length || !datasets.length) return Promise.resolve();

    return loadChartJs().then(() => {
        const context = chartCanvas.getContext('2d');
        if (!context || !window.Chart) return;

        if (chartCanvas._proAnalyticsChartInstance) {
            chartCanvas._proAnalyticsChartInstance.destroy();
        }

        const hasSecondaryAxis = datasets.some((dataset) => dataset.yAxisID === 'y1');

        chartCanvas._proAnalyticsChartInstance = new window.Chart(context, {
            type: 'bar',
            data: {
                labels,
                datasets: datasets.map((dataset) => {
                    const color = dataset.color || '#2f6df6';
                    const type = dataset.type || 'line';

                    return {
                        type,
                        label: dataset.label || '',
                        data: Array.isArray(dataset.values) ? dataset.values : [],
                        yAxisID: dataset.yAxisID || 'y',
                        borderColor: color,
                        backgroundColor: type === 'bar' ? hexToRgba(color, 0.34) : hexToRgba(color, dataset.fill ? 0.16 : 0),
                        fill: Boolean(dataset.fill),
                        borderWidth: type === 'bar' ? 0 : 3,
                        borderRadius: type === 'bar' ? 8 : 0,
                        maxBarThickness: 18,
                        tension: 0.38,
                        // Only bridge short gaps: with one review every few
                        // weeks, an unlimited span drew misleading diagonals
                        // across the whole chart.
                        spanGaps: dataset.yAxisID === 'y1' ? false : true,
                        pointRadius: type === 'bar' ? 0 : (dataset.yAxisID === 'y1' ? 4 : 3),
                        pointHoverRadius: type === 'bar' ? 0 : 5,
                        pointBackgroundColor: '#ffffff',
                        pointBorderColor: color,
                        pointBorderWidth: 2,
                    };
                }),
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: {
                    duration: 360,
                    easing: 'easeOutQuart',
                },
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                plugins: {
                    legend: {
                        display: false,
                    },
                    tooltip: {
                        backgroundColor: '#ffffff',
                        titleColor: '#6f86af',
                        bodyColor: '#17366f',
                        borderColor: '#d8e4fb',
                        borderWidth: 1,
                        padding: 12,
                        cornerRadius: 14,
                        caretPadding: 10,
                    },
                },
                scales: {
                    x: {
                        border: {
                            display: false,
                        },
                        grid: {
                            display: false,
                        },
                        ticks: {
                            color: '#7b8fb6',
                            font: {
                                size: 10,
                                weight: '600',
                            },
                            maxRotation: 0,
                            autoSkip: true,
                            maxTicksLimit: 7,
                        },
                    },
                    y: {
                        beginAtZero: true,
                        // Headroom so a lone "1 review" bar doesn't slam into
                        // the top edge of the chart.
                        grace: hasSecondaryAxis ? 1 : '8%',
                        border: {
                            display: false,
                        },
                        grid: {
                            color: '#e7eefc',
                        },
                        ticks: {
                            precision: 0,
                            color: '#7f95bc',
                            font: {
                                size: 11,
                                weight: '600',
                            },
                        },
                    },
                    y1: {
                        display: hasSecondaryAxis,
                        position: 'right',
                        beginAtZero: true,
                        // Slight headroom so a 5-star point isn't clipped by
                        // the top edge; the ticks callback hides ".25".
                        suggestedMax: 5,
                        max: 5.25,
                        border: {
                            display: false,
                        },
                        grid: {
                            drawOnChartArea: false,
                        },
                        ticks: {
                            stepSize: 1,
                            precision: 0,
                            color: '#7f95bc',
                            font: {
                                size: 11,
                                weight: '600',
                            },
                            callback(value) {
                                return Number.isInteger(Number(value)) ? value : '';
                            },
                        },
                    },
                },
            },
        });

        // Same mobile/Livewire-morph guard as the overview chart.
        ensureChartSized(chartCanvas._proAnalyticsChartInstance, chartCanvas);
    }).catch(() => {
        chartCanvas.closest('[data-pro-analytics-chart-card]')?.classList.add('has-chart-error');
    });
};

const initProAnalyticsCharts = (root = document) => {
    if (!document.body.classList.contains('page-pro-account')) return;

    collectNodes(root, '[data-pro-analytics-chart]').forEach((chartCanvas) => {
        renderProAnalyticsChart(chartCanvas);
    });
};

// Перемикач періоду на вкладці «Аналітика» тримався лише на wire:click, який
// CSP (no unsafe-eval) не дає Livewire виконати — тому кліки нічого не робили.
// Робимо AJAX без перезавантаження: тягнемо серверний рендер під новий період
// і підміняємо лише секцію #analytics-workspace (метрики + обидва графіки +
// джерела + дії), не чіпаючи решту shell і Livewire. Фолбек — повний перехід.
// Делеговано на document + капчер-фаза, щоб спрацювати навіть якщо Livewire
// теж навісив свій обробник.
let proAnalyticsPeriodSwitcherBound = false;
const initProAnalyticsPeriodSwitcher = () => {
    if (proAnalyticsPeriodSwitcherBound) return;
    proAnalyticsPeriodSwitcherBound = true;

    let isSwapping = false;

    const swapAnalyticsWorkspace = async (href) => {
        const workspace = document.querySelector('#analytics-workspace');
        if (!workspace) {
            window.location.assign(href);
            return;
        }

        isSwapping = true;
        setProLoadingState(workspace, true);

        try {
            const response = await fetch(href, {
                headers: {
                    Accept: 'text/html',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error(`Analytics request failed: ${response.status}`);
            }

            const html = await response.text();
            const nextDocument = new DOMParser().parseFromString(html, 'text/html');
            const nextWorkspace = nextDocument.querySelector('#analytics-workspace');

            if (!nextWorkspace) {
                throw new Error('Analytics workspace missing in response.');
            }

            workspace.replaceWith(nextWorkspace);
            initProAnalyticsCharts(nextWorkspace);

            // URL синхронізуємо без пуша в історію — як у фільтра в «Огляді»,
            // щоб кнопка «Назад» не гортала періоди, а виходила зі сторінки.
            window.history.replaceState({ proAccount: true }, '', href);
        } catch (error) {
            window.location.assign(href);
        } finally {
            isSwapping = false;
        }
    };

    document.addEventListener('click', (event) => {
        const item = event.target.closest('.pro-analytics-period-switcher__item');
        if (!item) return;

        const href = item.getAttribute('href');
        if (!href) return;

        event.preventDefault();

        if (item.classList.contains('is-active') || isSwapping) return;

        // Одразу підсвічуємо вибраний період — не чекаючи відповіді сервера,
        // щоб клік відчувався миттєвим навіть коли запит триває пару секунд.
        const switcher = item.closest('.pro-analytics-period-switcher');
        switcher?.querySelectorAll('.pro-analytics-period-switcher__item').forEach((node) => {
            node.classList.toggle('is-active', node === item);
        });

        swapAnalyticsWorkspace(href);
    }, true);
};

export const initProOverviewAnalytics = (root = document) => {
    if (!document.body.classList.contains('page-pro-account')) return;

    collectNodes(root, '[data-pro-overview-analytics]').forEach((analyticsCard) => {
        if (analyticsCard.dataset.analyticsBound === 'true') {
            // Livewire morphs update data-chart in place but keep this node
            // (and its "bound" flag), so without an explicit re-render here
            // the chart silently kept showing stale data — or nothing at
            // all when the morph replaced the <canvas>.
            const boundCanvas = analyticsCard.querySelector('[data-pro-overview-chart]');
            if (!boundCanvas) return;

            const payload = readChartPayload(boundCanvas);
            const signature = JSON.stringify(payload);
            const chartAttached = Boolean(window.Chart?.getChart?.(boundCanvas));

            if (analyticsCard.dataset.chartSignature !== signature || !chartAttached) {
                analyticsCard.dataset.chartSignature = signature;
                renderOverviewChart(analyticsCard, boundCanvas, payload);
            }

            return;
        }

        const chartCanvas = analyticsCard.querySelector('[data-pro-overview-chart]');
        if (!chartCanvas) return;

        analyticsCard.dataset.analyticsBound = 'true';

        const periodFilter = analyticsCard.querySelector('.pro-overview-period-filter');
        const periodLabel = analyticsCard.querySelector('.pro-overview-chip-button span');
        const periodOptions = Array.from(analyticsCard.querySelectorAll('[data-chart-period-option]'));
        const summaryValue = analyticsCard.querySelector('[data-chart-summary-value]');
        const summarySubtitle = analyticsCard.querySelector('[data-chart-summary-subtitle]');
        const summaryTrend = analyticsCard.querySelector('[data-chart-summary-trend]');
        const summaryTrendValue = analyticsCard.querySelector('[data-chart-summary-trend-value]');
        const summaryTrendIcon = analyticsCard.querySelector('[data-chart-summary-trend-icon]');
        const emptyState = analyticsCard.querySelector('.pro-overview-chart__empty');
        const profileId = analyticsCard.dataset.profileId || '';
        let activePeriod = null;
        let isLoading = false;

        const updateSummary = (summary) => {
            const trend = summary?.trend || { is_up: true, value: '0' };
            const isPositive = Boolean(trend.is_up);
            const isFlat = trend.kind === 'flat';

            if (summaryValue) summaryValue.textContent = summary.value || '0';
            if (summarySubtitle) summarySubtitle.textContent = summary.subtitle || '';
            if (periodLabel) periodLabel.textContent = summary.period_label || '';

            if (summaryTrend) {
                summaryTrend.classList.toggle('is-positive', !isFlat && isPositive);
                summaryTrend.classList.toggle('is-negative', !isFlat && !isPositive);
                summaryTrend.classList.toggle('is-neutral', isFlat);
            }

            if (summaryTrendValue) {
                summaryTrendValue.textContent = trend.value || '0';
            }

            if (summaryTrendIcon) {
                summaryTrendIcon.classList.toggle('fa-arrow-trend-up', !isFlat && isPositive);
                summaryTrendIcon.classList.toggle('fa-arrow-trend-down', !isFlat && !isPositive);
                summaryTrendIcon.classList.toggle('fa-minus', isFlat);
            }

            if (emptyState) {
                emptyState.hidden = Boolean(summary.has_meaningful_data);
            }
        };

        const updatePeriodOptions = (period) => {
            activePeriod = period;
            periodOptions.forEach((option) => {
                const isActive = option.dataset.period === period;
                option.classList.toggle('is-active', isActive);
                option.setAttribute('aria-selected', isActive ? 'true' : 'false');
            });
        };

        const buildAnalyticsUrl = (period) => {
            const url = new URL('/pro/account/analytics', window.location.origin);
            url.searchParams.set('profile', profileId);
            url.searchParams.set('analytics_period', period);
            return url.toString();
        };

        const syncHistory = (period) => {
            const url = new URL(window.location.href);
            url.searchParams.set('analytics_period', period);
            url.hash = 'overview-analytics';
            window.history.replaceState({}, '', url.toString());
        };

        const fetchAnalytics = async (period) => {
            if (!period || isLoading || period === activePeriod) {
                if (periodFilter) periodFilter.open = false;
                return;
            }

            isLoading = true;
            setProLoadingState(analyticsCard, true);

            try {
                const response = await fetch(buildAnalyticsUrl(period), {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                });

                if (!response.ok) {
                    throw new Error(`Analytics request failed: ${response.status}`);
                }

                const data = await response.json();

                if (!data?.chart || !data?.summary) {
                    throw new Error('Analytics response is incomplete');
                }

                chartCanvas.dataset.chart = JSON.stringify(data.chart);
                analyticsCard.dataset.chartSignature = JSON.stringify(readChartPayload(chartCanvas));
                updateSummary(data.summary);
                updatePeriodOptions(period);
                syncHistory(period);
                await renderOverviewChart(analyticsCard, chartCanvas, data.chart);
            } catch (error) {
                window.location.assign(`/pro/account?tab=overview&profile=${encodeURIComponent(profileId)}&analytics_period=${encodeURIComponent(period)}#overview-analytics`);
            } finally {
                isLoading = false;
                setProLoadingState(analyticsCard, false);
                if (periodFilter) periodFilter.open = false;
            }
        };

        periodOptions.forEach((option) => {
            option.addEventListener('click', (event) => {
                const period = option.dataset.period || '';

                if (!period || period === activePeriod) {
                    if (periodFilter) periodFilter.open = false;
                    return;
                }

                // Завжди AJAX: Livewire-шлях залежав від wire:click і ламався
                // (CSP блокує eval у livewire.js) — картка висіла в лоадері.
                event.preventDefault();
                fetchAnalytics(period);
            });
        });

        const initialPayload = readChartPayload(chartCanvas);
        analyticsCard.dataset.chartSignature = JSON.stringify(initialPayload);
        updatePeriodOptions(initialPayload.period || null);
        if (emptyState) {
            emptyState.hidden = Array.isArray(initialPayload.values) && initialPayload.values.some((value) => Number(value || 0) > 0);
        }

        renderOverviewChart(analyticsCard, chartCanvas, initialPayload);
    });
};

const PRO_ACCOUNT_TAB_LABELS = {
    overview: 'Огляд',
    profile: 'Профіль',
    reviews: 'Відгуки',
    leads: 'Заявки',
    analytics: 'Аналітика',
    notifications: 'Сповіщення',
    billing: 'Оплата',
    claims: 'Привʼязка профілів',
};

const parseTabVisibility = (value) => String(value || '')
    .split(',')
    .map((item) => item.trim())
    .filter(Boolean);

const normalizeProAccountTab = (tab) => (
    Object.prototype.hasOwnProperty.call(PRO_ACCOUNT_TAB_LABELS, tab) ? tab : 'overview'
);

const getProAccountUrlTab = () => {
    const url = new URL(window.location.href);
    const tab = url.searchParams.get('tab');

    return tab ? normalizeProAccountTab(tab) : null;
};

const getProAccountLivewireComponent = (shell) => {
    const livewireRoot = shell?.closest?.('[wire\\:id]');
    const componentId = livewireRoot?.getAttribute('wire:id');

    if (!componentId || typeof window.Livewire?.find !== 'function') return null;

    try {
        return window.Livewire.find(componentId);
    } catch (error) {
        return null;
    }
};

const syncProAccountTabToLivewire = (shell, tab) => {
    const component = getProAccountLivewireComponent(shell);
    const setLivewireProperty = typeof component?.$wire?.$set === 'function'
        ? component.$wire.$set.bind(component.$wire)
        : component?.$set?.bind(component);

    if (typeof setLivewireProperty !== 'function') return;

    try {
        setLivewireProperty('tab', tab, false);
    } catch (error) {
        // Keep client-side tabs usable even if Livewire internals change.
    }
};

const refreshProAccountLivewire = async (shell) => {
    const component = getProAccountLivewireComponent(shell);

    if (!component) return false;

    const refreshComponent = typeof component?.$wire?.$refresh === 'function'
        ? component.$wire.$refresh.bind(component.$wire)
        : typeof component?.$refresh === 'function'
            ? component.$refresh.bind(component)
            : null;

    if (typeof refreshComponent !== 'function') return false;

    await refreshComponent();

    return true;
};

const resizeProProfileReferenceTextarea = (field) => {
    const styles = window.getComputedStyle(field);
    const lineHeight = parseFloat(styles.lineHeight) || (parseFloat(styles.fontSize) * 1.45) || 20;
    const rows = parseInt(field.getAttribute('rows') || '4', 10) || 4;
    const verticalPadding = (parseFloat(styles.paddingTop) || 0) + (parseFloat(styles.paddingBottom) || 0);
    const verticalBorder = (parseFloat(styles.borderTopWidth) || 0) + (parseFloat(styles.borderBottomWidth) || 0);
    const minHeight = Math.ceil((lineHeight * rows) + verticalPadding + verticalBorder);

    field.style.minHeight = `${minHeight}px`;
    field.style.height = 'auto';
    field.style.height = `${Math.max(minHeight, field.scrollHeight)}px`;
};

const resizeProProfileReferenceTextareas = (root) => {
    collectNodes(root, '.pro-profile-reference-field textarea').forEach(resizeProProfileReferenceTextarea);
};

const initProReviewAvatarFallbacks = (root = document) => {
    collectNodes(root, '[data-review-avatar-image]').forEach((image) => {
        if (image.dataset.reviewAvatarFallbackBound === 'true') return;

        image.dataset.reviewAvatarFallbackBound = 'true';
        const showFallback = () => {
            const avatar = image.closest('[data-review-avatar]');
            const fallback = avatar?.querySelector('[data-review-avatar-fallback]');

            if (!avatar || !fallback) return;

            if (!avatar.dataset.seed && image.alt) {
                avatar.dataset.seed = image.alt;
            }

            fallback.hidden = false;
            avatar.classList.add('has-random-gradient');
            image.remove();
            initProfileVisualSeeds(avatar);
        };

        image.addEventListener('error', showFallback);

        if (image.complete && image.naturalWidth === 0) {
            showFallback();
        }
    });
};

const initProProfileImageFallbacks = (root = document) => {
    collectNodes(root, '[data-profile-image]').forEach((image) => {
        if (image.dataset.profileImageFallbackBound === 'true') return;

        image.dataset.profileImageFallbackBound = 'true';
        const showFallback = () => {
            const shell = image.closest('[data-profile-image-shell]');
            const fallback = shell?.querySelector('[data-profile-image-fallback]');

            if (!shell || !fallback) return;

            if (!shell.dataset.seed && image.alt) {
                shell.dataset.seed = image.alt;
            }

            fallback.hidden = false;
            shell.classList.add('is-fallback');
            image.remove();
            initProfileVisualSeeds(shell);
        };

        image.addEventListener('error', showFallback);

        if (image.complete && image.naturalWidth === 0) {
            showFallback();
        }
    });
};

const PRO_ACCOUNT_MOBILE_PROFILE_TABS = new Set(['notifications', 'billing']);

const isMobileProAccountViewport = () => window.matchMedia('(max-width: 760px)').matches;

const openProMobileProfileAccordion = (shell, tab) => {
    if (!PRO_ACCOUNT_MOBILE_PROFILE_TABS.has(tab)) return;

    const accordion = shell.querySelector(`[data-pro-mobile-profile-accordion="${tab}"]`);
    if (accordion instanceof HTMLDetailsElement) {
        accordion.open = true;
    }
};

const syncProMobileProfileAccordions = (shell, activeNestedTab = null) => {
    if (!shell) return;

    const accountMain = shell.querySelector('.account-main');
    const profilePanel = shell.querySelector('[data-pro-tab-panel="profile"]');
    const shouldNest = isMobileProAccountViewport();

    PRO_ACCOUNT_MOBILE_PROFILE_TABS.forEach((tab) => {
        const slot = shell.querySelector(`[data-pro-mobile-profile-slot="${tab}"]`);
        const accordion = shell.querySelector(`[data-pro-mobile-profile-accordion="${tab}"]`);
        const panel = shell.querySelector(`[data-pro-tab-panel="${tab}"], [data-pro-mobile-profile-panel="${tab}"]`);

        if (!panel) return;

        if (shouldNest && slot) {
            if (!slot.contains(panel)) {
                panel.dataset.proMobileProfilePanel = tab;
                panel.removeAttribute('data-pro-tab-panel');
                panel.hidden = false;
                panel.classList.add('pro-account-tab-stack--mobile-nested');
                slot.appendChild(panel);
            }

            if (accordion instanceof HTMLDetailsElement) {
                accordion.open = activeNestedTab === tab || accordion.open;
            }

            return;
        }

        if (panel.dataset.proMobileProfilePanel === tab && accountMain && profilePanel) {
            panel.setAttribute('data-pro-tab-panel', tab);
            delete panel.dataset.proMobileProfilePanel;
            panel.classList.remove('pro-account-tab-stack--mobile-nested');
            accountMain.insertBefore(panel, profilePanel);
        }
    });
};

const applyProAccountTabState = (shell, nextTab, options = {}) => {
    const { pushHistory = true, scrollTo = null, syncLivewire = true } = options;
    let tab = normalizeProAccountTab(nextTab);
    const mobileNestedTab = isMobileProAccountViewport() && PRO_ACCOUNT_MOBILE_PROFILE_TABS.has(tab)
        ? tab
        : null;
    if (mobileNestedTab) {
        tab = 'profile';
    }

    syncProMobileProfileAccordions(shell, mobileNestedTab);

    const label = PRO_ACCOUNT_TAB_LABELS[tab] || PRO_ACCOUNT_TAB_LABELS.overview;

    shell.dataset.proCurrentTab = tab;

    // Перемикання вкладки завершує редагування — повертаємо док на місце,
    // щоб клас не «залип» після втрати поля (напр. Livewire-перемалювання).
    window.clearTimeout(proAccountEditingBlurTimer);
    document.body.classList.remove('is-pro-account-editing');

    // Leaving the reviews tab with the mobile detail sheet open would keep
    // the body scroll-locked and the header invisible.
    if (tab !== 'reviews') {
        document.body.classList.remove('is-pro-review-detail-open');
        shell.querySelector('[data-pro-reviews-workspace]')?.classList.remove('is-detail-open');
    }

    if (syncLivewire) {
        syncProAccountTabToLivewire(shell, tab);
    }

    collectNodes(shell, '[data-pro-tab-trigger]').forEach((trigger) => {
        const target = trigger.dataset.proTabTarget || 'overview';
        const isPrimaryTabTrigger = trigger.hasAttribute('data-pro-tab-primary');
        trigger.classList.toggle('is-active', isPrimaryTabTrigger && target === tab);
        if (isPrimaryTabTrigger) {
            trigger.setAttribute('aria-selected', String(target === tab));
        }
    });

    collectNodes(shell, '[data-pro-tab-panel]').forEach((panel) => {
        panel.hidden = panel.dataset.proTabPanel !== tab;
    });

    collectNodes(shell, '[data-pro-tab-visible]').forEach((node) => {
        const visibleTabs = parseTabVisibility(node.dataset.proTabVisible);
        node.hidden = !visibleTabs.includes(tab);
    });

    collectNodes(shell, '[data-pro-tab-title]').forEach((node) => {
        node.textContent = label;
    });

    window.requestAnimationFrame(() => {
        resizeProProfileReferenceTextareas(shell);
        initProAnalyticsCharts(shell);
    });

    if (pushHistory) {
        const url = new URL(window.location.href);
        url.searchParams.set('tab', tab);
        window.history.pushState({ proAccountClientTab: tab }, '', url.toString());
    }

    if (scrollTo) {
        window.requestAnimationFrame(() => {
            scrollToHash(`#${scrollTo}`);
        });
    } else if (mobileNestedTab) {
        window.requestAnimationFrame(() => {
            openProMobileProfileAccordion(shell, mobileNestedTab);
            shell.querySelector(`[data-pro-mobile-profile-accordion="${mobileNestedTab}"]`)
                ?.scrollIntoView({ block: 'start', behavior: 'auto' });
        });
    } else if (pushHistory) {
        // A user-initiated tab switch starts from the top — otherwise the new
        // tab opens wherever the previous one was scrolled to.
        window.scrollTo({ top: 0, behavior: 'auto' });
    }
};

const initProAccountClientTabs = (shell) => {
    if (!shell) return;

    const initialTab = getProAccountUrlTab() || shell.dataset.proCurrentTab || shell.dataset.proInitialTab || 'overview';
    applyProAccountTabState(shell, initialTab, { pushHistory: false });

    if (shell.dataset.proAccountClientTabsBound === 'true') return;

    shell.dataset.proAccountClientTabsBound = 'true';

    shell.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-pro-tab-trigger]');
        if (!trigger || !shell.contains(trigger)) return;

        const targetTab = trigger.dataset.proTabTarget || 'overview';
        const scrollTarget = trigger.dataset.proTabScroll || null;

        event.preventDefault();

        // Сервер рендерить лише активну вкладку (щоб сторінка не важила
        // мегабайти) — відсутню панель довантажуємо: через Livewire-запит,
        // а без Livewire — ajax-навігацією із заміною shell.
        if (!PRO_ACCOUNT_MOBILE_PROFILE_TABS.has(targetTab) && !shell.querySelector(`[data-pro-tab-panel="${targetTab}"]`)) {
            (async () => {
                // Доберігаємо незбережені зміни форми перед перемальовуванням.
                if (proProfileFlushPending) {
                    try {
                        await proProfileFlushPending();
                    } catch (error) { /* не блокуємо перемикання */ }
                }

                const component = getProAccountLivewireComponent(shell);
                const setLive = typeof component?.$wire?.$set === 'function'
                    ? component.$wire.$set.bind(component.$wire)
                    : component?.$set?.bind(component);

                if (typeof setLive === 'function') {
                    if (scrollTarget) pendingProAccountScrollId = scrollTarget;
                    try {
                        await setLive('tab', targetTab);
                        return;
                    } catch (error) { /* впадемо на ajax-навігацію */ }
                }

                const url = new URL(window.location.href);
                url.searchParams.set('tab', targetTab);
                url.hash = scrollTarget ? `#${scrollTarget}` : '';
                navigateProAccount(url);
            })();
            return;
        }

        applyProAccountTabState(shell, targetTab, {
            pushHistory: true,
            scrollTo: scrollTarget,
        });
    });

    if (!proAccountClientTabsPopstateBound) {
        window.addEventListener('popstate', () => {
            if (!document.body.classList.contains('page-pro-account')) return;

            const currentShell = document.querySelector('[data-pro-account-shell][data-pro-account-livewire]');
            if (!currentShell) return;

            const url = new URL(window.location.href);
            const tab = url.searchParams.get('tab') || currentShell.dataset.proInitialTab || 'overview';

            // Панель могла бути не відрендерена (рендер лише активної вкладки).
            if (!currentShell.querySelector(`[data-pro-tab-panel="${tab}"]`)) {
                // Livewire з #[Url(history)] сам обробляє popstate і перемалює.
                if (getProAccountLivewireComponent(currentShell)) return;
                navigateProAccount(url, { historyMode: 'replace' });
                return;
            }

            applyProAccountTabState(currentShell, tab, { pushHistory: false });
        });

        proAccountClientTabsPopstateBound = true;
    }
};

const PRO_ACCOUNT_EDITING_FIELD_SELECTOR = 'input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]):not([type="button"]):not([type="submit"]):not([type="reset"]), textarea, select, [contenteditable="true"]';

// Ховаємо нижній док-меню, поки користувач редагує поле форми. На мобільному
// фіксований бар перекриває поле й клавіатуру та ділить низ зі sticky-баром
// збереження — тож коли фокус у текстовому полі кабінету, доку не місце.
// Клас на <body>: CSS з'їжджає док вниз і опускає бар збереження до краю.
let proAccountEditingBlurTimer = null;
const setProAccountEditing = (isEditing) => {
    window.clearTimeout(proAccountEditingBlurTimer);
    if (isEditing) {
        document.body.classList.add('is-pro-account-editing');
        return;
    }
    // Затримка, щоб док не блимав під час переходу між сусідніми полями
    // (focusout старого поля → focusin наступного встигає скасувати таймер).
    proAccountEditingBlurTimer = window.setTimeout(() => {
        document.body.classList.remove('is-pro-account-editing');
    }, 160);
};

const initProAccountWorkspace = (shell) => {
    if (!document.body.classList.contains('page-pro-account')) return;
    if (!shell || shell.dataset.proAccountWorkspaceBound === 'true') return;

    shell.dataset.proAccountWorkspaceBound = 'true';

    shell.addEventListener('focusin', (event) => {
        if (event.target.closest?.(PRO_ACCOUNT_EDITING_FIELD_SELECTOR)) {
            setProAccountEditing(true);
        }
    });
    shell.addEventListener('focusout', () => setProAccountEditing(false));

    const category = shell.querySelector('[data-pro-category]');
    const subcategory = shell.querySelector('[data-pro-subcategory]');
    const serviceEditor = shell.querySelector('[data-service-editor]');
    const descriptionInput = shell.querySelector('[data-profile-description-input]');
    const descriptionCount = shell.querySelector('[data-profile-description-count]');
    const cityInput = shell.querySelector('[data-pro-city]');
    const regionInput = shell.querySelector('[data-pro-region]');
    const cityList = shell.querySelector('#pro-account-city-list');
    const socialManager = shell.querySelector('[data-social-manager]');
    const mediaManager = shell.querySelector('[data-media-manager]');
    const reviewWorkspace = shell.querySelector('[data-pro-reviews-workspace]');
    const billingWorkspace = shell.querySelector('[data-pro-billing-workspace]');
    const notificationsForm = shell.querySelector('[data-pro-notifications-form]');
    const notificationsReadAllButton = shell.querySelector('[data-pro-notifications-read-all]');
    const profileEditCards = Array.from(shell.querySelectorAll('[data-profile-edit-card]'));
    const jsonRepeaters = Array.from(shell.querySelectorAll('[data-pro-json-repeater]'));
    const profileForm = shell.querySelector('#pro-profile-form');
    const profileToast = shell.querySelector('[data-profile-toast]');
    const savebarStatus = shell.querySelector('[data-profile-savebar-status]');
    const savebarText = shell.querySelector('[data-profile-savebar-text]');
    const referenceTextareas = Array.from(shell.querySelectorAll('.pro-profile-reference-field textarea'));
    const submitButtons = Array.from(shell.querySelectorAll('button[form="pro-profile-form"], #pro-profile-form button[type="submit"]'));

    const categoryChildren = parseJsonScript(shell, '#pro-account-category-children', {});
    const serviceCandidates = parseJsonScript(shell, '#pro-account-service-candidates', []);
    const regionCityDirectory = parseJsonScript(shell, '#pro-account-region-city-directory', []);
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    let autosaveControls = [];
    let isSubmittingProfile = false;
    let isSubmittingNotifications = false;
    let queuedProfileSave = null;
    const autosaveInputTimers = new Map();
    let profileToastTimer = null;
    let profileToastHideTimer = null;

    // Оновлює статус у липкому барі під формою («Зберігається…» / «Збережено» / помилка).
    const setSavebarState = (message, state = 'success') => {
        if (!savebarStatus || !savebarText) return;

        savebarText.textContent = message;
        savebarStatus.classList.remove('is-saving', 'is-error');
        if (state === 'saving') savebarStatus.classList.add('is-saving');
        if (state === 'error') savebarStatus.classList.add('is-error');
    };

    const setProfileStatus = (message, type = 'success') => {
        setSavebarState(
            type === 'error' ? (message || 'Не вдалося зберегти зміни.') : 'Збережено щойно',
            type === 'error' ? 'error' : 'success'
        );

        if (!profileToast) return;

        window.clearTimeout(profileToastTimer);
        window.clearTimeout(profileToastHideTimer);

        profileToast.textContent = message;
        profileToast.hidden = false;
        profileToast.classList.remove('is-success', 'is-error');
        profileToast.classList.add(type === 'error' ? 'is-error' : 'is-success');

        requestAnimationFrame(() => {
            profileToast.classList.add('is-visible');
        });

        profileToastTimer = window.setTimeout(() => {
            profileToast.classList.remove('is-visible');
            profileToastHideTimer = window.setTimeout(() => {
                profileToast.hidden = true;
            }, 220);
        }, type === 'error' ? 3600 : 1800);
    };

    const updateNotificationsUnreadState = (count) => {
        shell.querySelectorAll('[data-pro-notifications-unread-count]').forEach((node) => {
            node.textContent = String(Math.max(0, Number(count || 0)));
        });

        shell.querySelectorAll('[data-pro-notifications-nav-badge]').forEach((badge) => {
            const safeCount = Math.max(0, Number(count || 0));
            badge.textContent = String(Math.min(safeCount, 99));
            badge.hidden = safeCount <= 0;
        });

        if (notificationsReadAllButton) {
            notificationsReadAllButton.disabled = Number(count || 0) <= 0;
        }
    };

    const rebuildSubcategoryOptions = ({ preserveCurrent = true } = {}) => {
        if (!subcategory) return;

        const rootCategoryId = Number(category?.value || 0);
        const currentValue = preserveCurrent ? String(subcategory.value || '') : '';
        const items = Array.isArray(categoryChildren[String(rootCategoryId)] ?? categoryChildren[rootCategoryId])
            ? (categoryChildren[String(rootCategoryId)] ?? categoryChildren[rootCategoryId])
            : [];

        subcategory.innerHTML = '';

        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = items.length ? 'Оберіть підкатегорію' : 'Підкатегорій немає';
        subcategory.appendChild(placeholder);

        items.forEach((item) => {
            const option = document.createElement('option');
            option.value = String(item.id || '');
            option.textContent = String(item.name || '');

            if (currentValue !== '' && option.value === currentValue) {
                option.selected = true;
            }

            subcategory.appendChild(option);
        });

        if (!items.some((item) => String(item.id || '') === currentValue)) {
            subcategory.value = '';
        }

        subcategory.disabled = items.length === 0;
    };

    const initCategoryChildSelects = () => {
        shell.querySelectorAll('[data-category-root-select]').forEach((rootSelect) => {
            const targetId = rootSelect.dataset.categoryChildTarget || '';
            const childSelect = targetId ? shell.querySelector(`#${CSS.escape(targetId)}`) : null;
            if (!childSelect) return;

            const rebuild = ({ preserveCurrent = true } = {}) => {
                const rootCategoryId = Number(rootSelect.value || 0);
                const currentValue = preserveCurrent
                    ? String(childSelect.value || childSelect.dataset.selectedValue || '')
                    : '';
                const items = Array.isArray(categoryChildren[String(rootCategoryId)] ?? categoryChildren[rootCategoryId])
                    ? (categoryChildren[String(rootCategoryId)] ?? categoryChildren[rootCategoryId])
                    : [];

                childSelect.innerHTML = '';

                const placeholder = document.createElement('option');
                placeholder.value = '';
                placeholder.textContent = items.length ? 'Оберіть підкатегорію' : 'Підкатегорій немає';
                childSelect.appendChild(placeholder);

                items.forEach((item) => {
                    const option = document.createElement('option');
                    option.value = String(item.id || '');
                    option.textContent = String(item.name || '');

                    if (currentValue !== '' && option.value === currentValue) {
                        option.selected = true;
                    }

                    childSelect.appendChild(option);
                });

                if (!items.some((item) => String(item.id || '') === currentValue)) {
                    childSelect.value = '';
                }

                childSelect.disabled = items.length === 0;
            };

            rebuild();
            rootSelect.addEventListener('change', () => rebuild({ preserveCurrent: false }));
        });
    };

    const normalizeLocationValue = (value) => String(value || '')
        .toLowerCase()
        .replace(/[’'`]/g, '')
        .replace(/\b(місто|м)\.?\s+/gu, '')
        .replace(/,\s*україна$/gu, '')
        .replace(/\s+україна$/gu, '')
        .replace(/[\s,.-]+/gu, ' ')
        .trim();

    const regionById = new Map(regionCityDirectory.map((region) => [String(region.id), region]));
    const cityToRegionId = new Map();

    regionCityDirectory.forEach((region) => {
        (Array.isArray(region.cities) ? region.cities : []).forEach((city) => {
            cityToRegionId.set(normalizeLocationValue(city), String(region.id));
        });
    });

    const rebuildCityOptions = ({ preserveCurrent = true } = {}) => {
        if (!cityList) return;

        const selectedRegion = regionById.get(String(regionInput?.value || ''));
        const cities = selectedRegion
            ? (Array.isArray(selectedRegion.cities) ? selectedRegion.cities : [])
            : regionCityDirectory.flatMap((region) => Array.isArray(region.cities) ? region.cities : []);
        const uniqueCities = Array.from(new Set(cities)).sort((left, right) => left.localeCompare(right, 'uk'));

        cityList.innerHTML = '';

        uniqueCities.forEach((city) => {
            const option = document.createElement('option');
            option.value = city;
            cityList.appendChild(option);
        });

        if (!preserveCurrent && cityInput) {
            cityInput.value = '';
        }
    };

    const syncRegionByCity = () => {
        if (!cityInput || !regionInput) return false;

        const normalizedCity = normalizeLocationValue(cityInput.value);
        if (!normalizedCity) return false;

        const inferredRegionId = cityToRegionId.get(normalizedCity);
        if (!inferredRegionId || regionInput.value === inferredRegionId) {
            return false;
        }

        regionInput.value = inferredRegionId;
        return true;
    };

    const getControlState = (control) => {
        if (control.matches('input[type="checkbox"]')) {
            return control.checked ? '1' : '0';
        }

        if (control.matches('input[type="radio"]')) {
            return control.checked ? (control.value || '1') : '0';
        }

        if (control instanceof HTMLSelectElement && control.multiple) {
            return Array.from(control.selectedOptions).map((option) => option.value).join('|');
        }

        return control.value;
    };

    const markCommittedState = (control) => {
        control.dataset.autosaveCommitted = getControlState(control);
    };

    const isControlDirty = (control) => getControlState(control) !== (control.dataset.autosaveCommitted ?? '');

    const collectAutosaveControls = () => {
        if (!profileForm) return [];

        return Array.from(profileForm.querySelectorAll('input, select, textarea')).filter((control) => {
            const type = (control.getAttribute('type') || '').toLowerCase();
            return Boolean(control.name) && !control.disabled && type !== 'hidden' && type !== 'submit' && type !== 'button' && type !== 'file';
        });
    };

    const collectCurrentNames = (control) => {
        const names = new Set([control.name]);

        if (control.name === 'category_id') {
            names.add('subcategory_id');
            names.add('service_names[]');
        }

        if (control.name === 'subcategory_id') {
            names.add('category_id');
        }

        if (control.name === 'city') {
            names.add('region_id');
        }

        return names;
    };

    const buildProfileFormData = ({ currentNames = new Set(), submitter = null } = {}) => {
        const formData = new FormData();

        Array.from(profileForm?.elements ?? []).forEach((element) => {
            if (!(element instanceof HTMLElement) || !('name' in element) || !element.name || element.disabled) {
                return;
            }

            const type = ('type' in element ? String(element.type).toLowerCase() : '');
            const shouldUseCurrentValue = currentNames.has(element.name) || !autosaveControls.includes(element) || !isControlDirty(element);

            if (type === 'submit' || type === 'button') {
                return;
            }

            if (type === 'file') {
                if (shouldUseCurrentValue && element instanceof HTMLInputElement && element.files?.length) {
                    Array.from(element.files).forEach((file) => {
                        formData.append(element.name, file);
                    });
                }

                return;
            }

            if (type === 'checkbox') {
                const checked = shouldUseCurrentValue ? element.checked : ((element.dataset.autosaveCommitted ?? '0') === '1');
                if (checked) {
                    formData.append(element.name, element.value || '1');
                }
                return;
            }

            if (type === 'radio') {
                const checked = shouldUseCurrentValue ? element.checked : ((element.dataset.autosaveCommitted ?? '0') === (element.value || '1'));
                if (checked) {
                    formData.append(element.name, element.value || '1');
                }
                return;
            }

            if (element instanceof HTMLSelectElement && element.multiple) {
                const values = shouldUseCurrentValue
                    ? Array.from(element.selectedOptions).map((option) => option.value)
                    : (element.dataset.autosaveCommitted ?? '').split('|').filter(Boolean);

                values.forEach((value) => formData.append(element.name, value));
                return;
            }

            if (type === 'hidden') {
                formData.append(element.name, element.value);
                return;
            }

            formData.append(element.name, shouldUseCurrentValue ? element.value : (element.dataset.autosaveCommitted ?? ''));
        });

        if (submitter?.name) {
            formData.set(submitter.name, submitter.value || '1');
        }

        return formData;
    };

    const syncCommittedStateForNames = (names) => {
        autosaveControls.forEach((control) => {
            if (names.has(control.name)) {
                markCommittedState(control);
            }
        });
    };

    const syncProfileFieldValue = (name, value) => {
        if (!profileForm) return;

        const field = profileForm.querySelector(`[name="${name}"]`);
        if (!(field instanceof HTMLInputElement) && !(field instanceof HTMLTextAreaElement) && !(field instanceof HTMLSelectElement)) {
            return;
        }

        field.value = value ?? '';
        markCommittedState(field);
    };

    const submitProfileForm = async ({ currentNames = new Set(), submitter = null } = {}) => {
        if (!profileForm) return null;

        if (isSubmittingProfile) {
            queuedProfileSave = {
                currentNames: new Set([...(queuedProfileSave?.currentNames ?? []), ...currentNames]),
                submitter: queuedProfileSave?.submitter || submitter,
            };
            return null;
        }

        isSubmittingProfile = true;
        submitButtons.forEach((button) => {
            button.disabled = true;
        });
        profileForm.classList.add('is-saving');
        setSavebarState('Зберігається…', 'saving');

        try {
            const response = await fetch(profileForm.action, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: buildProfileFormData({ currentNames, submitter }),
            });

            const contentType = response.headers.get('content-type') || '';
            const payload = contentType.includes('application/json') ? await response.json() : null;

            if (!response.ok) {
                const firstError = payload?.errors ? Object.values(payload.errors).flat()[0] : null;
                throw new Error(firstError || payload?.message || 'Не вдалося зберегти зміни.');
            }

            syncCommittedStateForNames(currentNames.size ? currentNames : new Set(autosaveControls.map((control) => control.name)));
            if (payload?.profile) {
                syncProfileFieldValue('seo_title', payload.profile.seo_title ?? '');
                syncProfileFieldValue('seo_description', payload.profile.seo_description ?? '');
                syncProfileFieldValue('region_id', payload.profile.region_id ?? '');
            }

            setProfileStatus(payload?.message || 'Зміни збережено.');
            return payload;
        } catch (error) {
            setProfileStatus(error.message || 'Не вдалося зберегти зміни.', 'error');
            return null;
        } finally {
            submitButtons.forEach((button) => {
                button.disabled = false;
            });
            profileForm?.classList.remove('is-saving');
            isSubmittingProfile = false;

            if (queuedProfileSave) {
                const nextSave = queuedProfileSave;
                queuedProfileSave = null;
                await submitProfileForm(nextSave);
            }
        }
    };

    const registerAutosaveControls = () => {
        if (!profileForm) return;

        autosaveControls = collectAutosaveControls();

        const scheduleAutosave = (control, delay = 850) => {
            window.clearTimeout(autosaveInputTimers.get(control));

            autosaveInputTimers.set(control, window.setTimeout(async () => {
                autosaveInputTimers.delete(control);
                if (!isControlDirty(control)) return;
                await submitProfileForm({ currentNames: collectCurrentNames(control) });
            }, delay));
        };

        const flushAutosave = async (control) => {
            window.clearTimeout(autosaveInputTimers.get(control));
            autosaveInputTimers.delete(control);
            if (!isControlDirty(control)) return;
            await submitProfileForm({ currentNames: collectCurrentNames(control) });
        };

        profileForm.querySelectorAll('input, select, textarea').forEach((control) => {
            if (!autosaveControls.includes(control) || control.dataset.autosaveBound === 'true') {
                return;
            }

            markCommittedState(control);
            control.dataset.autosaveBound = 'true';

            if (control.matches('input[type="checkbox"], input[type="radio"], select')) {
                control.addEventListener('change', () => {
                    window.setTimeout(async () => {
                        await flushAutosave(control);
                    }, 0);
                });
                return;
            }

            control.addEventListener('input', () => scheduleAutosave(control));
            control.addEventListener('blur', () => flushAutosave(control));
        });
    };

    // ─── Захист від втрати незбережених змін ───

    const hasPendingProfileChanges = () => Boolean(profileForm) && (
        autosaveInputTimers.size > 0
        || isSubmittingProfile
        || Boolean(queuedProfileSave)
        || autosaveControls.some((control) => isControlDirty(control))
    );

    // Скидає відкладені таймери автосейву й одразу зберігає все брудне —
    // викликається перед ajax-навігацією (заміною shell), щоб зміни не зникли.
    const flushPendingProfileSaves = async () => {
        if (!profileForm) return;

        const names = new Set();
        autosaveInputTimers.forEach((timer, control) => {
            window.clearTimeout(timer);
            collectCurrentNames(control).forEach((name) => names.add(name));
        });
        autosaveInputTimers.clear();

        autosaveControls
            .filter((control) => isControlDirty(control))
            .forEach((control) => {
                collectCurrentNames(control).forEach((name) => names.add(name));
            });

        if (names.size) {
            await submitProfileForm({ currentNames: names });
        }
    };

    proProfileHasPendingChanges = hasPendingProfileChanges;
    proProfileFlushPending = flushPendingProfileSaves;

    if (!proProfileUnloadGuardBound) {
        proProfileUnloadGuardBound = true;
        window.addEventListener('beforeunload', (event) => {
            if (proProfileHasPendingChanges?.()) {
                event.preventDefault();
                event.returnValue = '';
            }
        });
    }

    rebuildSubcategoryOptions();
    initCategoryChildSelects();
    rebuildCityOptions();

    category?.addEventListener('change', () => {
        rebuildSubcategoryOptions({ preserveCurrent: false });
    });

    regionInput?.addEventListener('change', () => {
        rebuildCityOptions();
    });

    cityInput?.addEventListener('change', () => {
        if (syncRegionByCity()) {
            rebuildCityOptions();
        }
    });

    cityInput?.addEventListener('blur', () => {
        if (syncRegionByCity()) {
            rebuildCityOptions();
        }
    });

    if (descriptionInput && descriptionCount) {
        const syncDescriptionCount = () => {
            descriptionCount.textContent = `${descriptionInput.value.length}/500`;
        };

        descriptionInput.addEventListener('input', syncDescriptionCount);
        syncDescriptionCount();
    }

    referenceTextareas.forEach((field) => {
        const resizeToContent = () => {
            resizeProProfileReferenceTextarea(field);
        };

        resizeToContent();
        field.addEventListener('input', resizeToContent);
        field.addEventListener('focus', () => {
            field.style.border = '1px solid #2f6df4';
            field.style.boxShadow = '0 0 0 1px #2f6df4';
        });
        field.addEventListener('blur', () => {
            field.style.border = '1px solid #d5e0f5';
            field.style.boxShadow = 'none';
        });
    });

    registerAutosaveControls();

    if (reviewWorkspace) {
        const reviewList = reviewWorkspace.querySelector('[data-review-list]');
        const detailPanel = reviewWorkspace.querySelector('[data-review-detail-panel]');
        const searchInput = reviewWorkspace.querySelector('[data-review-search]');
        const sortSelect = reviewWorkspace.querySelector('[data-review-sort]');
        const filterButtons = Array.from(reviewWorkspace.querySelectorAll('[data-review-filter]'));
        const summaryFilterButtons = Array.from(reviewWorkspace.querySelectorAll('[data-review-summary-filter]'));
        const emptyState = reviewWorkspace.querySelector('[data-review-empty]');
        const moreButton = reviewWorkspace.querySelector('[data-review-more]');
        const reviewFilterStorageKey = 'pro-account-review-filter';
        const activeReviewStorageKey = 'pro-account-active-review';
        const storedFilter = window.sessionStorage?.getItem(reviewFilterStorageKey) || 'all';
        let activeFilter = filterButtons.some((button) => button.dataset.reviewFilter === storedFilter) ? storedFilter : 'all';
        const REVIEWS_PAGE_SIZE = 15;
        let reviewRenderLimit = REVIEWS_PAGE_SIZE;
        // Detail panels are fetched on demand instead of being embedded per
        // review — with hundreds of reviews the inline copies made the page huge.
        const reviewDetailCache = new Map();

        const reviewCards = () => Array.from(reviewWorkspace.querySelectorAll('[data-review-card]'));
        const isMobileReviewLayout = () => window.matchMedia('(max-width: 760px)').matches;
        const setMobileReviewDetailState = (isOpen) => {
            document.body.classList.toggle('is-pro-review-detail-open', Boolean(isOpen && isMobileReviewLayout()));
        };
        const persistActiveFilter = () => {
            window.sessionStorage?.setItem(reviewFilterStorageKey, activeFilter);
        };
        const persistActiveReviewId = (reviewId) => {
            if (!reviewId) {
                window.sessionStorage?.removeItem(activeReviewStorageKey);
                return;
            }

            window.sessionStorage?.setItem(activeReviewStorageKey, String(reviewId));
        };
        const getPersistedActiveReviewId = () => window.sessionStorage?.getItem(activeReviewStorageKey) || '';
        const closeMobileReviewDetail = () => {
            reviewCards().forEach((item) => item.classList.remove('is-active'));
            reviewWorkspace.classList.remove('is-detail-open');
            setMobileReviewDetailState(false);
            persistActiveReviewId('');
        };
        const updateFilterButtons = () => {
            filterButtons.forEach((item) => {
                item.classList.toggle('is-active', item.dataset.reviewFilter === activeFilter);
            });
            summaryFilterButtons.forEach((item) => {
                item.classList.toggle('is-active', item.dataset.reviewSummaryFilter === activeFilter);
            });
        };
        const getReviewCardById = (reviewId) => reviewWorkspace.querySelector(`[data-review-card][data-review-id="${String(reviewId)}"]`);
        const getReviewMetrics = () => {
            let published = 0;
            let hidden = 0;
            let withoutReply = 0;
            let negative = 0;
            let positive = 0;

            reviewCards().forEach((card) => {
                const status = card.dataset.reviewStatus || '';
                const rating = Number(card.dataset.reviewRating || 0);
                const hasReply = card.dataset.reviewHasReply === 'true';

                if (status === 'hidden') {
                    hidden += 1;
                    return;
                }

                if (status !== 'published') {
                    return;
                }

                published += 1;

                if (!hasReply) {
                    withoutReply += 1;
                }

                if (rating <= 2) {
                    negative += 1;
                }

                if (rating >= 4) {
                    positive += 1;
                }
            });

            return { published, hidden, withoutReply, negative, positive };
        };
        const updateReviewCounters = () => {
            const metrics = getReviewMetrics();
            const filterCounts = {
                all: metrics.published,
                without_reply: metrics.withoutReply,
                negative: metrics.negative,
                positive: metrics.positive,
                hidden: metrics.hidden,
            };
            const summaryCounts = {
                without_reply: metrics.withoutReply,
                negative: metrics.negative,
                hidden: metrics.hidden,
            };

            filterButtons.forEach((button) => {
                const key = button.dataset.reviewFilter || '';
                const counter = button.querySelector('span');
                if (counter && Object.hasOwn(filterCounts, key)) {
                    counter.textContent = String(filterCounts[key]);
                }
            });

            summaryFilterButtons.forEach((button) => {
                const key = button.dataset.reviewSummaryFilter || '';
                const counter = button.querySelector('strong');
                if (counter && Object.hasOwn(summaryCounts, key)) {
                    counter.textContent = String(summaryCounts[key]);
                }
            });
        };
        const setReviewVisibilityLabels = (root, status, { compact = false } = {}) => {
            if (!root) return;

            const input = root.querySelector('[name="status"]');
            const button = root.querySelector('button');
            const icon = button?.querySelector('i');
            const label = button?.querySelector('span');
            const isHidden = status === 'hidden';

            if (input) {
                input.value = isHidden ? 'published' : 'hidden';
            }

            if (button) {
                button.setAttribute('aria-label', isHidden ? 'Показати відгук' : 'Сховати відгук');
            }

            if (icon) {
                icon.classList.remove('fa-eye', 'fa-eye-slash');
                icon.classList.add(isHidden ? 'fa-eye' : 'fa-eye-slash');
            }

            if (label) {
                label.textContent = isHidden
                    ? (compact ? 'Показати' : 'Показати відгук')
                    : (compact ? 'Сховати' : 'Сховати відгук');
            }
        };
        const setReviewReplyLabels = (root, hasReply, replyBody = '') => {
            if (!root) return;

            const badge = root.querySelector('.pro-reviews-card__top em');
            if (badge) {
                badge.classList.toggle('is-answered', hasReply);
                badge.classList.toggle('is-waiting', !hasReply);
                if (hasReply) {
                    badge.innerHTML = '<i class="fa-solid fa-circle-check" aria-hidden="true"></i>';
                    badge.setAttribute('title', 'Відповідь опублікована');
                    badge.setAttribute('aria-label', 'Відповідь опублікована');
                } else {
                    badge.textContent = 'Без відповіді';
                    badge.removeAttribute('title');
                    badge.removeAttribute('aria-label');
                }
            }

            const replyForm = root.querySelector('[data-review-reply-form]');
            const replyButtonLabel = replyForm?.querySelector('.pro-reviews-detail__primary span');
            const textarea = replyForm?.querySelector('[data-review-reply-text]');
            const remainingOutput = replyForm?.querySelector('[data-review-reply-left]');

            if (replyButtonLabel) {
                replyButtonLabel.textContent = hasReply ? 'Оновити відповідь' : 'Опублікувати відповідь';
            }

            if (textarea instanceof HTMLTextAreaElement) {
                textarea.value = replyBody;
                textarea.textContent = replyBody;

                if (remainingOutput) {
                    const max = Number(textarea.getAttribute('maxlength') || 1000);
                    remainingOutput.textContent = String(Math.max(0, max - textarea.value.length));
                }
            }
        };
        const updateReviewCardState = (reviewId, { status = null, hasReply = null, replyBody = null } = {}) => {
            const card = getReviewCardById(reviewId);
            if (!card) return;

            if (status) {
                card.dataset.reviewStatus = status;
                card.classList.toggle('is-hidden-review', status === 'hidden');
                setReviewVisibilityLabels(card.querySelector('.pro-reviews-card__quick-visibility'), status, { compact: true });
            }

            if (typeof hasReply === 'boolean') {
                card.dataset.reviewHasReply = hasReply ? 'true' : 'false';
                setReviewReplyLabels(card, hasReply, replyBody || '');
            }

            // The cached detail markup is stale after any mutation — refetch next time.
            reviewDetailCache.delete(String(reviewId));

            if (card.classList.contains('is-active')) {
                const activeDetail = detailPanel?.querySelector('.pro-reviews-detail');
                if (status) {
                    activeDetail?.classList.toggle('is-hidden-review', status === 'hidden');
                    setReviewVisibilityLabels(detailPanel?.querySelector('[data-review-visibility-form]'), status);
                }

                if (typeof hasReply === 'boolean') {
                    setReviewReplyLabels(detailPanel, hasReply, replyBody || '');
                }
            }
        };
        const setReviewMutationLoading = (reviewId, isLoading) => {
            reviewWorkspace.classList.toggle('is-refreshing', isLoading);

            const card = getReviewCardById(reviewId);
            card?.classList.toggle('is-updating', isLoading);

            if (card?.classList.contains('is-active')) {
                detailPanel?.querySelector('.pro-reviews-detail')?.classList.toggle('is-updating', isLoading);
            }
        };
        const renderEmptyReviewDetail = () => {
            if (!detailPanel) return;

            detailPanel.innerHTML = `
                <div class="pro-reviews-detail pro-reviews-detail--empty">
                    <h2>Відгук</h2>
                    <p>За поточним фільтром немає доступних відгуків.</p>
                </div>
            `;
            reviewWorkspace.classList.remove('is-detail-open');
            setMobileReviewDetailState(false);
            persistActiveReviewId('');
        };

        const initReviewDetail = () => {
            const textarea = detailPanel?.querySelector('[data-review-reply-text]');
            const leftOutput = detailPanel?.querySelector('[data-review-reply-left]');
            const closeButton = detailPanel?.querySelector('[data-review-detail-close]');

            const syncReplyCount = () => {
                if (!textarea || !leftOutput) return;

                const max = Number(textarea.getAttribute('maxlength') || 1000);
                leftOutput.textContent = String(Math.max(0, max - textarea.value.length));
            };

            closeButton?.addEventListener('click', () => {
                closeMobileReviewDetail();
            });

            const reportToggle = detailPanel?.querySelector('[data-review-report-toggle]');
            const reportForm = detailPanel?.querySelector('[data-review-report-form]');
            reportToggle?.addEventListener('click', () => {
                if (!reportForm) return;
                reportForm.hidden = !reportForm.hidden;
            });

            textarea?.addEventListener('input', syncReplyCount);
            syncReplyCount();
        };

        const renderReviewDetailHtml = (html) => {
            detailPanel.innerHTML = html;
            if (isMobileReviewLayout()) {
                detailPanel.scrollTop = 0;
            }
            initReviewDetail();
        };

        const activateReview = async (card) => {
            if (!card || !detailPanel) return;

            reviewCards().forEach((item) => item.classList.toggle('is-active', item === card));

            const reviewId = String(card.dataset.reviewId || '');
            const detailUrl = card.dataset.reviewDetailUrl || '';
            if (!detailUrl) return;

            reviewWorkspace.classList.add('is-detail-open');
            setMobileReviewDetailState(true);
            persistActiveReviewId(reviewId);

            const cachedHtml = reviewDetailCache.get(reviewId);
            if (cachedHtml) {
                renderReviewDetailHtml(cachedHtml);
                return;
            }

            detailPanel.innerHTML = `
                <div class="pro-reviews-detail pro-reviews-detail--empty">
                    <h2>Відгук</h2>
                    <p>Завантаження відгуку…</p>
                </div>
            `;

            try {
                const response = await fetch(detailUrl, {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                });
                const payload = await response.json();

                if (!response.ok || !payload?.html) {
                    throw new Error(payload?.message || 'Не вдалося завантажити відгук.');
                }

                reviewDetailCache.set(reviewId, payload.html);

                // The user may have tapped another review while this one loaded.
                if (getPersistedActiveReviewId() !== reviewId) return;

                renderReviewDetailHtml(payload.html);
            } catch (error) {
                if (getPersistedActiveReviewId() !== reviewId) return;

                detailPanel.innerHTML = `
                    <div class="pro-reviews-detail pro-reviews-detail--empty">
                        <h2>Відгук</h2>
                        <p>${error.message || 'Не вдалося завантажити відгук.'} Спробуйте ще раз.</p>
                    </div>
                `;
            }
        };

        const matchesReviewFilter = (card) => {
            const status = card.dataset.reviewStatus || '';
            const rating = Number(card.dataset.reviewRating || 0);
            const hasReply = card.dataset.reviewHasReply === 'true';

            if (activeFilter === 'without_reply') {
                // Імпортовані відгуки не рахуються як «без відповіді».
                return status === 'published' && !hasReply && card.dataset.reviewImported !== 'true';
            }

            if (activeFilter === 'negative') {
                return status === 'published' && rating <= 2;
            }

            if (activeFilter === 'positive') {
                return status === 'published' && rating >= 4;
            }

            if (activeFilter === 'hidden') {
                return status === 'hidden';
            }

            return status === 'published';
        };

        const applyReviewControls = () => {
            const query = (searchInput?.value || '').trim().toLowerCase();
            const matchedCards = reviewCards().filter((card) => {
                const searchText = (card.dataset.reviewSearchText || '').toLowerCase();
                return matchesReviewFilter(card) && (!query || searchText.includes(query));
            });
            // Reveal matches in pages so a long history doesn't render at once.
            const visibleCards = matchedCards.slice(0, reviewRenderLimit);

            reviewCards().forEach((card) => {
                const isVisible = visibleCards.includes(card);
                card.hidden = !isVisible;
                if (!isVisible) {
                    card.classList.remove('is-active');
                }
            });

            if (moreButton) {
                const remaining = matchedCards.length - visibleCards.length;
                moreButton.hidden = remaining <= 0;
                const label = moreButton.querySelector('span');
                if (label && remaining > 0) {
                    label.textContent = `Показати ще (${remaining})`;
                }
            }

            if (emptyState) {
                emptyState.hidden = visibleCards.length > 0;
            }

            if (visibleCards.length === 0) {
                reviewCards().forEach((card) => card.classList.remove('is-active'));
                renderEmptyReviewDetail();
                return;
            }

            if (isMobileReviewLayout() && !reviewWorkspace.classList.contains('is-detail-open')) {
                visibleCards.forEach((card) => card.classList.remove('is-active'));
            }

            const activeCard = reviewWorkspace.querySelector('[data-review-card].is-active:not([hidden])');
            if (!activeCard && isMobileReviewLayout()) {
                reviewWorkspace.classList.remove('is-detail-open');
                setMobileReviewDetailState(false);
                persistActiveReviewId('');
                return;
            }

            if (!activeCard && visibleCards.length > 0) {
                const persistedReviewId = getPersistedActiveReviewId();
                const nextCard = visibleCards.find((card) => card.dataset.reviewId === persistedReviewId) || visibleCards[0];
                activateReview(nextCard);
            }
        };

        // Toast з кнопкою «Скасувати» після приховання — захист від
        // випадкового тапу без модального підтвердження.
        let undoToastEl = null;
        let undoToastTimer = null;

        const showHideUndoToast = (form) => {
            undoToastEl?.remove();
            window.clearTimeout(undoToastTimer);

            const toast = document.createElement('div');
            toast.className = 'pro-review-undo-toast';
            toast.setAttribute('role', 'status');
            toast.innerHTML = `
                <i class="fa-solid fa-eye-slash" aria-hidden="true"></i>
                <span>Відгук сховано</span>
                <button type="button" class="pro-review-undo-toast__btn">Скасувати</button>
            `;
            toast.querySelector('button').addEventListener('click', async () => {
                toast.remove();
                window.clearTimeout(undoToastTimer);
                // Після приховання форма вже перемкнута на status=published —
                // повторний сабміт повертає відгук.
                await submitReviewAction(form);
            });

            document.body.appendChild(toast);
            undoToastEl = toast;
            undoToastTimer = window.setTimeout(() => toast.remove(), 6000);
        };

        const submitReviewAction = async (form) => {
            const reviewId = form.dataset.reviewId || form.querySelector('[name="review_id"]')?.value || '';
            const submitButtons = Array.from(form.querySelectorAll('button[type="submit"]'));
            const isVisibilityMutation = form.matches('[data-review-visibility-form]');
            const isReplyMutation = form.matches('[data-review-reply-form]');
            const isReportMutation = form.matches('[data-review-report-form]');
            const replyBody = isReplyMutation
                ? String(form.querySelector('[name="body"]')?.value || '').trim()
                : '';

            submitButtons.forEach((button) => {
                button.disabled = true;
            });
            form.classList.add('is-saving');
            setReviewMutationLoading(reviewId, true);

            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: new FormData(form),
                    credentials: 'same-origin',
                });

                const contentType = response.headers.get('content-type') || '';
                const payload = contentType.includes('application/json') ? await response.json() : null;

                if (!response.ok) {
                    const firstError = payload?.errors ? Object.values(payload.errors).flat()[0] : null;
                    throw new Error(firstError || payload?.message || 'Не вдалося оновити відгук.');
                }

                persistActiveReviewId(payload?.review_id || reviewId);

                if (isVisibilityMutation && payload?.review_status) {
                    updateReviewCardState(reviewId, { status: payload.review_status });

                    if (payload.review_status === 'hidden') {
                        // Замість звичайного тоста — undo, щоб можна було повернути.
                        showHideUndoToast(form);
                    } else {
                        setProfileStatus(payload?.message || 'Відгук оновлено.');
                    }
                } else {
                    setProfileStatus(payload?.message || 'Відгук оновлено.');
                }

                if (isReplyMutation) {
                    updateReviewCardState(reviewId, {
                        hasReply: true,
                        replyBody,
                    });
                }

                if (isReportMutation) {
                    reviewDetailCache.delete(String(reviewId));
                    const reportBlock = form.closest('[data-review-report-block]');
                    if (reportBlock) {
                        reportBlock.innerHTML = `
                            <p class="pro-reviews-report__sent">
                                <i class="fa-regular fa-flag" aria-hidden="true"></i>
                                Скаргу надіслано — модерація розгляне її найближчим часом.
                            </p>
                        `;
                    }
                }

                updateReviewCounters();
                applyReviewControls();
            } catch (error) {
                setProfileStatus(error.message || 'Не вдалося оновити відгук.', 'error');
            } finally {
                setReviewMutationLoading(reviewId, false);
                submitButtons.forEach((button) => {
                    button.disabled = false;
                });
                form.classList.remove('is-saving');
            }
        };

        const sortReviews = () => {
            if (!reviewList || !sortSelect) {
                applyReviewControls();
                return;
            }

            const cards = reviewCards();
            const sortValue = sortSelect.value;

            cards.sort((a, b) => {
                const dateA = Number(a.dataset.reviewDate || 0);
                const dateB = Number(b.dataset.reviewDate || 0);
                const ratingA = Number(a.dataset.reviewRating || 0);
                const ratingB = Number(b.dataset.reviewRating || 0);

                if (sortValue === 'oldest') return dateA - dateB;
                if (sortValue === 'rating_desc') return ratingB - ratingA;
                if (sortValue === 'rating_asc') return ratingA - ratingB;
                return dateB - dateA;
            });

            cards.forEach((card) => reviewList.appendChild(card));
            applyReviewControls();
        };

        reviewWorkspace.addEventListener('click', (event) => {
            const selectButton = event.target.closest('[data-review-select]');
            if (!selectButton) return;
            activateReview(selectButton.closest('[data-review-card]'));
        });

        filterButtons.forEach((button) => {
            button.addEventListener('click', () => {
                activeFilter = button.dataset.reviewFilter || 'all';
                reviewRenderLimit = REVIEWS_PAGE_SIZE;
                persistActiveFilter();
                updateFilterButtons();
                applyReviewControls();
            });
        });

        summaryFilterButtons.forEach((button) => {
            button.addEventListener('click', () => {
                const nextFilter = button.dataset.reviewSummaryFilter;
                if (!nextFilter) return;

                // Tapping the already-active summary card acts as a toggle back to "all".
                activeFilter = activeFilter === nextFilter ? 'all' : nextFilter;
                reviewRenderLimit = REVIEWS_PAGE_SIZE;
                persistActiveFilter();
                updateFilterButtons();
                applyReviewControls();
            });
        });

        moreButton?.addEventListener('click', () => {
            reviewRenderLimit += REVIEWS_PAGE_SIZE;
            applyReviewControls();
        });

        reviewWorkspace.addEventListener('submit', async (event) => {
            const form = event.target;
            if (!(form instanceof HTMLFormElement)) return;
            if (!form.matches('[data-review-reply-form], [data-review-visibility-form], [data-review-report-form]')) return;

            event.preventDefault();
            await submitReviewAction(form);
        });

        searchInput?.addEventListener('input', () => {
            reviewRenderLimit = REVIEWS_PAGE_SIZE;
            applyReviewControls();
        });
        sortSelect?.addEventListener('change', sortReviews);
        window.addEventListener('resize', () => {
            setMobileReviewDetailState(reviewWorkspace.classList.contains('is-detail-open'));
        });
        updateReviewCounters();
        updateFilterButtons();
        initReviewDetail();
        sortReviews();
    }

    if (billingWorkspace) {
        const billingForms = Array.from(billingWorkspace.querySelectorAll('[data-pro-billing-form]'));
        const billingPeriodToggles = Array.from(billingWorkspace.querySelectorAll('[data-pro-billing-period-toggle]'));
        const billingPlanList = billingWorkspace.querySelector('[data-pro-billing-plan-list]');
        const billingPlanCards = Array.from(billingWorkspace.querySelectorAll('[data-pro-billing-plan-card]'));
        const billingPeriodInputs = Array.from(billingWorkspace.querySelectorAll('[data-pro-billing-period-input]'));
        let billingPendingRequests = 0;
        const normalizeBillingPeriod = (period) => ['month', 'year', 'halfyear'].includes(period) ? period : 'halfyear';
        let selectedBillingPeriod = normalizeBillingPeriod(billingPlanList?.dataset.currentPeriod || 'halfyear');

        const updateBillingPlanControls = (period = selectedBillingPeriod) => {
            selectedBillingPeriod = normalizeBillingPeriod(period);

            const currentPlan = billingPlanList?.dataset.currentPlan || 'start';
            const currentPeriod = normalizeBillingPeriod(billingPlanList?.dataset.currentPeriod || 'halfyear');

            billingPeriodToggles.forEach((toggle) => {
                const isActive = toggle.dataset.proBillingPeriodToggle === selectedBillingPeriod;
                toggle.classList.toggle('is-active', isActive);
                toggle.setAttribute('aria-selected', isActive ? 'true' : 'false');
            });

            billingPeriodInputs.forEach((input) => {
                input.value = selectedBillingPeriod;
            });

            billingWorkspace.querySelectorAll('[data-pro-billing-price]').forEach((price) => {
                price.textContent = selectedBillingPeriod === 'year'
                    ? (price.dataset.yearly || price.textContent)
                    : (price.dataset.monthly || price.textContent);
            });

            billingWorkspace.querySelectorAll('[data-pro-billing-price-period]').forEach((pricePeriod) => {
                pricePeriod.textContent = selectedBillingPeriod === 'year'
                    ? (pricePeriod.dataset.yearly || pricePeriod.textContent)
                    : (pricePeriod.dataset.monthly || pricePeriod.textContent);
            });

            billingPlanCards.forEach((card) => {
                const plan = card.dataset.plan || '';
                const isCurrent = currentPlan === plan && (plan === 'start' || currentPeriod === selectedBillingPeriod);
                card.classList.toggle('is-active', isCurrent);

                const badge = card.querySelector('[data-pro-billing-current-badge]');
                if (badge) {
                    badge.hidden = !isCurrent;
                }
            });

            billingWorkspace.querySelectorAll('[data-pro-billing-plan-button]').forEach((button) => {
                const form = button.closest('[data-pro-billing-plan-form]');
                const plan = form?.dataset.plan || '';
                const isCurrent = currentPlan === plan && currentPeriod === selectedBillingPeriod;
                const isRenewal = plan === 'pro' && isCurrent;
                const label = button.querySelector('span');

                button.disabled = isCurrent && !isRenewal;
                button.classList.toggle('btn--ghost', isCurrent && !isRenewal);
                button.classList.toggle('btn--primary', !isCurrent || isRenewal);

                if (label) {
                    label.textContent = isRenewal
                        ? (button.dataset.switchLabel || 'Продовжити PRO')
                        : isCurrent
                        ? (button.dataset.activeLabel || 'Активний зараз')
                        : (currentPlan === 'start'
                            ? (button.dataset.connectLabel || 'Підключити')
                            : (button.dataset.switchLabel || 'Перейти'));
                    button.dataset.defaultLabel = label.textContent;
                }
            });
        };

        const setBillingLoadingState = (form, isLoading) => {
            const relatedBlock = form.closest('[data-pro-billing-block]');
            const submitButtons = Array.from(form.querySelectorAll('button[type="submit"]'));

            billingPendingRequests += isLoading ? 1 : -1;
            billingPendingRequests = Math.max(0, billingPendingRequests);

            billingWorkspace.classList.toggle('is-refreshing', billingPendingRequests > 0);
            billingWorkspace.setAttribute('aria-busy', billingPendingRequests > 0 ? 'true' : 'false');
            relatedBlock?.classList.toggle('is-updating', isLoading);

            submitButtons.forEach((button) => {
                if (!button.dataset.defaultLabel) {
                    button.dataset.defaultLabel = button.querySelector('span')?.textContent?.trim() || '';
                }

                button.disabled = isLoading || button.hasAttribute('data-static-disabled');
                button.classList.toggle('is-loading', isLoading);
                button.setAttribute('aria-busy', isLoading ? 'true' : 'false');

                const label = button.querySelector('span');
                if (label) {
                    label.textContent = isLoading ? 'Обробка...' : (button.dataset.defaultLabel || label.textContent);
                }
            });
        };

        const restoreBillingButtonState = (form) => {
            form.querySelectorAll('button[type="submit"]').forEach((button) => {
                const isStaticDisabled = button.hasAttribute('data-static-disabled');
                button.disabled = isStaticDisabled;
                button.classList.remove('is-loading');
                button.setAttribute('aria-busy', 'false');

                const label = button.querySelector('span');
                if (label && button.dataset.defaultLabel) {
                    label.textContent = button.dataset.defaultLabel;
                }
            });
        };

        billingForms.forEach((form) => {
            form.querySelectorAll('button[type="submit"]').forEach((button) => {
                if (button.disabled && !button.matches('[data-pro-billing-plan-button]')) {
                    button.setAttribute('data-static-disabled', 'true');
                }
            });
        });

        billingPeriodToggles.forEach((toggle) => {
            toggle.addEventListener('click', () => {
                updateBillingPlanControls(toggle.dataset.proBillingPeriodToggle || 'halfyear');
            });
        });

        updateBillingPlanControls(selectedBillingPeriod);

        billingWorkspace.addEventListener('submit', async (event) => {
            const form = event.target;
            if (!(form instanceof HTMLFormElement)) return;
            if (!form.matches('[data-pro-billing-form]')) return;

            event.preventDefault();

            setBillingLoadingState(form, true);

            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: new FormData(form),
                    credentials: 'same-origin',
                });

                const contentType = response.headers.get('content-type') || '';
                const payload = contentType.includes('application/json') ? await response.json() : null;

                if (!response.ok) {
                    const firstError = payload?.errors ? Object.values(payload.errors).flat()[0] : null;
                    throw new Error(firstError || payload?.message || 'Не вдалося оновити оплату.');
                }

                if (payload?.status === 'redirect' && payload?.redirect_url) {
                    window.location.assign(payload.redirect_url);
                    return;
                }

                setProfileStatus(payload?.message || 'Стан підписки оновлено.');

                pendingProAccountScrollId = 'billing-workspace';
                const refreshed = await refreshProAccountLivewire(shell);

                if (!refreshed && payload?.redirect_url) {
                    window.location.assign(payload.redirect_url);
                    return;
                }
            } catch (error) {
                pendingProAccountScrollId = null;
                setProfileStatus(error.message || 'Не вдалося оновити оплату.', 'error');
            } finally {
                setBillingLoadingState(form, false);
                restoreBillingButtonState(form);
                updateBillingPlanControls(selectedBillingPeriod);
            }
        });
    }

    if (notificationsForm) {
        let notificationsAutosaveTimer = null;

        const setNotificationsLoadingState = (isLoading) => {
            notificationsForm.classList.toggle('is-saving', isLoading);
            notificationsForm.setAttribute('aria-busy', isLoading ? 'true' : 'false');
            notificationsForm.querySelectorAll('input[type="checkbox"]').forEach((input) => {
                input.disabled = isLoading;
            });

            if (notificationsReadAllButton) {
                notificationsReadAllButton.disabled = isLoading || shell.querySelectorAll('.pro-notification-item.is-unread').length <= 0;
            }
        };

        const submitNotificationsForm = async () => {
            if (isSubmittingNotifications) return;

            isSubmittingNotifications = true;
            setNotificationsLoadingState(true);

            try {
                const response = await fetch(notificationsForm.action, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: new FormData(notificationsForm),
                    credentials: 'same-origin',
                });

                const payload = await response.json();
                if (!response.ok) {
                    const firstError = payload?.errors ? Object.values(payload.errors).flat()[0] : null;
                    throw new Error(firstError || payload?.message || 'Не вдалося зберегти сповіщення.');
                }

                setProfileStatus(payload?.message || 'Налаштування сповіщень збережено.');
            } catch (error) {
                setProfileStatus(error.message || 'Не вдалося зберегти сповіщення.', 'error');
            } finally {
                isSubmittingNotifications = false;
                setNotificationsLoadingState(false);
                updateNotificationsUnreadState(shell.querySelectorAll('.pro-notification-item.is-unread').length);
            }
        };

        notificationsForm.addEventListener('change', (event) => {
            const control = event.target;
            if (!(control instanceof HTMLInputElement) || control.type !== 'checkbox') return;

            window.clearTimeout(notificationsAutosaveTimer);
            notificationsAutosaveTimer = window.setTimeout(() => {
                submitNotificationsForm();
            }, 220);
        });

        notificationsReadAllButton?.addEventListener('click', async () => {
            const targetUrl = notificationsReadAllButton.dataset.url || '';
            if (!targetUrl) return;

            notificationsReadAllButton.disabled = true;
            notificationsReadAllButton.setAttribute('aria-busy', 'true');

            try {
                const response = await fetch(targetUrl, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    credentials: 'same-origin',
                });

                const payload = await response.json();
                if (!response.ok) {
                    throw new Error(payload?.message || 'Не вдалося оновити сповіщення.');
                }

                shell.querySelectorAll('.pro-notification-item.is-unread').forEach((item) => item.classList.remove('is-unread'));
                updateNotificationsUnreadState(0);
                setProfileStatus(payload?.message || 'Сповіщення позначено як прочитані.');
            } catch (error) {
                notificationsReadAllButton.disabled = false;
                setProfileStatus(error.message || 'Не вдалося оновити сповіщення.', 'error');
            } finally {
                notificationsReadAllButton.setAttribute('aria-busy', 'false');
            }
        });
    }

    if (profileForm?.matches('[data-ajax-profile-form]')) {
        profileForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            const submitter = event.submitter;
            const currentNames = new Set(autosaveControls.map((control) => control.name));
            await submitProfileForm({ currentNames, submitter });
        });

        profileForm.addEventListener('keydown', (event) => {
            if (event.key !== 'Enter' || event.target?.tagName === 'TEXTAREA') return;

            const target = event.target;
            if (!(target instanceof HTMLInputElement)) return;

            event.preventDefault();
            target.blur();
        });
    }

    profileEditCards.forEach((card) => {
        const toggle = card.querySelector('[data-profile-card-toggle]');
        const icon = toggle?.querySelector('i');

        if (!toggle) return;

        const syncEditState = () => {
            const isEditing = card.classList.contains('is-editing');
            toggle.setAttribute('aria-expanded', String(isEditing));
            toggle.setAttribute('aria-label', isEditing ? 'Завершити редагування' : (toggle.dataset.defaultLabel || toggle.getAttribute('aria-label') || 'Редагувати'));
            if (icon) {
                icon.classList.toggle('fa-regular', !isEditing);
                icon.classList.toggle('fa-solid', isEditing);
                icon.classList.toggle('fa-pen-to-square', !isEditing);
                icon.classList.toggle('fa-check', isEditing);
            }
        };

        toggle.dataset.defaultLabel = toggle.getAttribute('aria-label') || 'Редагувати';

        toggle.addEventListener('click', () => {
            if (card.classList.contains('is-editing')) {
                card.classList.remove('is-editing');
                syncEditState();
                return;
            }

            profileEditCards.forEach((otherCard) => {
                if (otherCard === card) return;

                otherCard.classList.remove('is-editing');
                const otherToggle = otherCard.querySelector('[data-profile-card-toggle]');
                const otherIcon = otherToggle?.querySelector('i');

                if (otherToggle) {
                    otherToggle.setAttribute('aria-expanded', 'false');
                    otherToggle.setAttribute('aria-label', otherToggle.dataset.defaultLabel || 'Редагувати');
                }

                if (otherIcon) {
                    otherIcon.classList.add('fa-regular', 'fa-pen-to-square');
                    otherIcon.classList.remove('fa-solid', 'fa-check');
                }
            });

            card.classList.add('is-editing');
            syncEditState();

            const firstField = card.querySelector('.pro-profile-card-edit-panel input:not([type="hidden"]):not([hidden]), .pro-profile-card-edit-panel select, .pro-profile-card-edit-panel textarea');
            firstField?.focus({ preventScroll: true });
        });

        card.addEventListener('keydown', (event) => {
            if (!card.classList.contains('is-editing')) return;

            if (event.key === 'Escape') {
                card.classList.remove('is-editing');
                syncEditState();
                toggle.focus({ preventScroll: true });
                return;
            }

            if (event.key === 'Enter' && event.target?.tagName !== 'TEXTAREA') {
                event.preventDefault();
                event.target.blur();
            }
        });

        syncEditState();
    });

    // Phone mask is handled globally by modules/phone-mask.js (scans every
    // [data-phone-mask] input, including ones re-rendered by Livewire).

    if (jsonRepeaters.length) {
        jsonRepeaters.forEach((repeater) => {
            if (repeater.dataset.repeaterBound === 'true') return;
            repeater.dataset.repeaterBound = 'true';

            const hiddenInput = repeater.querySelector(`input[name="${repeater.dataset.targetName || ''}"]`);
            const list = repeater.querySelector('[data-repeater-list]');
            const addButton = repeater.querySelector('[data-repeater-add]');
            const emptyState = repeater.querySelector('[data-repeater-empty]');
            const template = repeater.querySelector('template[data-repeater-template]');
            const maxItems = Number(repeater.dataset.maxItems || 0);
            let saveTimer = null;

            if (!(hiddenInput instanceof HTMLInputElement) || !list || !(template instanceof HTMLTemplateElement)) {
                return;
            }

            const getItems = () => Array.from(list.querySelectorAll('[data-repeater-item]'));

            const serializeItems = () => getItems()
                .map((item) => {
                    const payload = {};

                    item.querySelectorAll('[data-repeater-key]').forEach((field) => {
                        const key = field.dataset.repeaterKey;
                        if (!key) return;

                        if (field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement || field instanceof HTMLSelectElement) {
                            payload[key] = field.value.trim();
                        }
                    });

                    return payload;
                })
                .filter((item) => Object.values(item).some((value) => String(value || '').trim() !== ''));

            const syncHeadings = () => {
                getItems().forEach((item, index) => {
                    const heading = item.querySelector('.pro-profile-json-item__head strong');
                    if (!heading) return;

                    if (hiddenInput.name === 'owner_profile_advantages_json') {
                        heading.textContent = `Перевага ${index + 1}`;
                        return;
                    }

                    if (hiddenInput.name === 'owner_profile_certificates_json') {
                        heading.textContent = `Документ ${index + 1}`;
                        return;
                    }

                    heading.textContent = `Питання ${index + 1}`;
                });
            };

            const syncUiState = () => {
                const count = getItems().length;

                if (emptyState) {
                    emptyState.hidden = count > 0;
                }

                if (addButton && maxItems > 0) {
                    addButton.disabled = count >= maxItems;
                    addButton.hidden = count >= maxItems;
                }

                syncHeadings();
            };

            const commit = () => {
                const serialized = JSON.stringify(serializeItems());
                hiddenInput.value = serialized;
                syncProfileFieldValue(hiddenInput.name, serialized);
                syncUiState();
            };

            const scheduleSave = (delay = 650) => {
                commit();
                window.clearTimeout(saveTimer);
                saveTimer = window.setTimeout(() => {
                    submitProfileForm({ currentNames: new Set([hiddenInput.name]) });
                }, delay);
            };

            addButton?.addEventListener('click', () => {
                if (maxItems > 0 && getItems().length >= maxItems) return;

                const fragment = template.content.cloneNode(true);
                const nextItem = fragment.querySelector('[data-repeater-item]');
                list.appendChild(fragment);
                syncUiState();
                commit();

                const firstField = nextItem?.querySelector('input, textarea, select');
                if (firstField instanceof HTMLElement) {
                    window.requestAnimationFrame(() => {
                        firstField.focus({ preventScroll: true });
                    });
                }
            });

            repeater.addEventListener('input', (event) => {
                if (!(event.target instanceof Element) || !event.target.closest('[data-repeater-item]')) return;
                scheduleSave();
            });

            repeater.addEventListener('change', (event) => {
                if (!(event.target instanceof Element) || !event.target.closest('[data-repeater-item]')) return;
                scheduleSave(250);
            });

            repeater.addEventListener('click', (event) => {
                const removeButton = event.target.closest('[data-repeater-remove]');
                if (!removeButton) return;

                removeButton.closest('[data-repeater-item]')?.remove();
                scheduleSave(150);
            });

            commit();
        });
    }

    if (socialManager) {
        const getSocialRows = () => Array.from(socialManager.querySelectorAll('[data-social-row]'));
        const getSocialRow = (key) => socialManager.querySelector(`[data-social-row="${key}"]`);
        const getSocialInput = (row) => row?.querySelector('[data-social-input]') ?? null;

        const syncSocialState = () => {
            getSocialRows().forEach((row) => {
                const input = getSocialInput(row);
                const hasValue = Boolean(input?.value.trim());
                const isOpen = row.dataset.socialOpen === 'true';
                const isActive = hasValue || isOpen;

                row.classList.toggle('is-active', isActive);
                row.classList.toggle('is-empty', !isActive);

                if (!isActive) {
                    row.dataset.socialOpen = '';
                }
            });
        };

        const openSocialRow = (key) => {
            const row = getSocialRow(key);
            if (!row) return;

            const input = getSocialInput(row);
            row.classList.add('is-entering', 'is-active');
            row.classList.remove('is-empty');
            row.dataset.socialOpen = 'true';

            window.requestAnimationFrame(() => {
                row.classList.remove('is-entering');
            });

            if (input) {
                window.requestAnimationFrame(() => {
                    input.focus({ preventScroll: true });
                });
            }

            syncSocialState();
        };

        socialManager.addEventListener('click', (event) => {
            const addButton = event.target.closest('[data-social-add]');
            if (addButton) {
                openSocialRow(addButton.dataset.socialAdd);
                return;
            }

            const removeButton = event.target.closest('[data-social-remove]');
            if (!removeButton) return;

            const row = getSocialRow(removeButton.dataset.socialRemove);
            const input = getSocialInput(row);

            if (input) {
                input.value = '';
            }

            if (row) {
                row.classList.add('is-removing');
                window.setTimeout(() => {
                    row.classList.remove('is-active', 'is-removing');
                    row.classList.add('is-empty');
                    row.dataset.socialOpen = '';
                    syncSocialState();
                }, 180);
            } else {
                syncSocialState();
            }

            if (input) {
                submitProfileForm({ currentNames: collectCurrentNames(input) });
            }
        });

        socialManager.addEventListener('input', (event) => {
            const input = event.target;
            if (!(input instanceof HTMLInputElement) || !input.name.startsWith('social_')) return;

            const row = input.closest('[data-social-row]');
            row?.classList.add('is-active');
            row?.classList.remove('is-empty');

            if (row) {
                row.dataset.socialOpen = 'true';
            }

            syncSocialState();
        }, true);

        socialManager.addEventListener('blur', (event) => {
            const input = event.target;
            if (!(input instanceof HTMLInputElement) || !input.name.startsWith('social_')) return;

            const row = input.closest('[data-social-row]');
            if (!row) return;

            if (input.value.trim() === '') {
                row.classList.add('is-removing');
                window.setTimeout(() => {
                    row.classList.remove('is-active', 'is-removing');
                    row.classList.add('is-empty');
                    row.dataset.socialOpen = '';
                    syncSocialState();
                }, 180);
            }
        }, true);

        syncSocialState();
    }

    if (mediaManager) {
        const mediaGrid = mediaManager.querySelector('[data-media-grid]');
        const mediaAddButton = mediaManager.querySelector('[data-media-add]');
        const mediaAddCard = mediaManager.querySelector('[data-media-add-card]');
        const mediaAddIcon = mediaManager.querySelector('.pro-profile-media-add-card__icon i');
        const mediaAddLabel = mediaManager.querySelector('[data-media-add-label]');
        const mediaFileInput = mediaManager.querySelector('[data-media-file-input]');
        const mediaAvatarButton = mediaManager.querySelector('[data-media-avatar-add]');
        const mediaAvatarCard = mediaManager.querySelector('[data-media-avatar-card]');
        const mediaAvatarLabel = mediaManager.querySelector('[data-media-avatar-label]');
        const mediaAvatarInput = mediaManager.querySelector('[data-media-avatar-input]');
        const mediaGalleryInput = mediaManager.querySelector('textarea[name="gallery_urls"]');
        const mediaLogoInput = mediaManager.querySelector('input[name="logo_url"]');
        const mediaBannerInput = mediaManager.querySelector('input[name="banner_url"]');

        const normalizeMediaUrl = (value) => value.replace(/\s+/g, ' ').trim();
        const toPublicMediaUrl = (value) => {
            const normalizedValue = normalizeMediaUrl(value);
            if (!normalizedValue) return '';

            if (/^(https?:)?\/\//i.test(normalizedValue)) {
                return normalizedValue;
            }

            if (normalizedValue.startsWith('/storage/') || normalizedValue.startsWith('storage/')) {
                return `/${normalizedValue.replace(/^\/+/, '')}`;
            }

            if (normalizedValue.startsWith('/')) {
                return normalizedValue;
            }

            return `/storage/${normalizedValue.replace(/^\/+/, '')}`;
        };

        const normalizeGalleryItem = (item) => {
            if (typeof item === 'string') {
                const rawUrl = normalizeMediaUrl(item);
                if (!rawUrl) return null;

                return {
                    raw: rawUrl,
                    url: toPublicMediaUrl(rawUrl),
                    visible: true,
                    title: '',
                };
            }

            if (!item || typeof item !== 'object') {
                return null;
            }

            const rawUrl = normalizeMediaUrl(item.raw || item.url || '');
            if (!rawUrl) {
                return null;
            }

            const publicUrl = normalizeMediaUrl(item.url || '');

            return {
                raw: rawUrl,
                url: publicUrl || toPublicMediaUrl(rawUrl),
                visible: item.visible !== false,
                title: normalizeMediaUrl(item.title || ''),
            };
        };

        const serializeGalleryItems = (items) => JSON.stringify(
            items
                .map(normalizeGalleryItem)
                .filter(Boolean)
                .map((item) => ({
                    url: item.raw,
                    visible: item.visible,
                    title: item.title,
                }))
        );

        const getGalleryItems = () => {
            const rawValue = normalizeMediaUrl(mediaGalleryInput?.value || '');
            if (!rawValue) {
                return [];
            }

            try {
                const decoded = JSON.parse(rawValue);
                if (Array.isArray(decoded)) {
                    return decoded.map(normalizeGalleryItem).filter(Boolean);
                }
            } catch (error) {
                // Falls back to the legacy newline-separated list.
            }

            return rawValue
                .split(/\r\n|\r|\n/)
                .map(normalizeGalleryItem)
                .filter(Boolean);
        };

        const setGalleryItems = (items, { markCommitted = false } = {}) => {
            if (!mediaGalleryInput) return;

            const serialized = items.length ? serializeGalleryItems(items) : '';

            if (markCommitted) {
                syncProfileFieldValue('gallery_urls', serialized);
                return;
            }

            mediaGalleryInput.value = serialized;
        };

        const createAssetCard = ({ kind, rawUrl, publicUrl, alt, badge, removeLabel }) => {
            const card = document.createElement('article');
            card.className = 'pro-profile-media-card';
            card.dataset.mediaCard = '';
            card.dataset.mediaKind = kind;

            const image = document.createElement('img');
            image.src = publicUrl;
            image.alt = alt;
            image.loading = 'lazy';

            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'pro-profile-media-remove';
            remove.dataset.mediaRemove = kind;
            remove.setAttribute('aria-label', removeLabel);
            remove.innerHTML = '<i class="fa-solid fa-xmark" aria-hidden="true"></i>';

            const badgeNode = document.createElement('span');
            badgeNode.className = 'pro-profile-media-badge';
            badgeNode.textContent = badge;

            card.append(image, remove, badgeNode);

            if (kind === 'logo' && rawUrl) {
                card.dataset.mediaUrl = rawUrl;
            }

            return card;
        };

        const createMediaCard = (item) => {
            const card = document.createElement('article');
            card.className = `pro-profile-media-card is-entering${item.visible ? '' : ' is-hidden-media'}`;
            card.dataset.mediaCard = '';
            card.dataset.mediaKind = 'gallery';
            card.dataset.mediaUrl = item.raw;
            card.dataset.mediaVisible = item.visible ? '1' : '0';

            const image = document.createElement('img');
            image.src = item.url || toPublicMediaUrl(item.raw);
            image.alt = 'Фото профілю';
            image.loading = 'lazy';

            const menuTrigger = document.createElement('button');
            menuTrigger.type = 'button';
            menuTrigger.className = 'pro-profile-media-menu-trigger';
            menuTrigger.dataset.mediaMenuTrigger = '';
            menuTrigger.setAttribute('aria-label', 'Дії з фото');
            menuTrigger.setAttribute('aria-haspopup', 'true');
            menuTrigger.setAttribute('aria-expanded', 'false');
            menuTrigger.innerHTML = '<i class="fa-solid fa-ellipsis" aria-hidden="true"></i>';

            const menu = document.createElement('div');
            menu.className = 'pro-profile-media-menu';
            menu.dataset.mediaMenu = '';
            menu.hidden = true;
            menu.innerHTML = `
                <button type="button" data-media-toggle-visibility="${item.raw}" data-media-visible="${item.visible ? '1' : '0'}">
                    <i class="fa-regular ${item.visible ? 'fa-eye-slash' : 'fa-eye'}" aria-hidden="true"></i>
                    <span>${item.visible ? 'Приховати' : 'Показати'}</span>
                </button>
                <button type="button" data-media-make-avatar="${item.raw}">
                    <i class="fa-regular fa-user" aria-hidden="true"></i>
                    <span>Зробити аватаркою</span>
                </button>
                <button type="button" class="is-danger" data-media-remove-gallery="${item.raw}">
                    <i class="fa-regular fa-trash-can" aria-hidden="true"></i>
                    <span>Видалити фото</span>
                </button>
            `;

            card.append(image, menuTrigger, menu);
            return card;
        };

        const renderGalleryCards = () => {
            if (!mediaGrid || !mediaAddCard) return;

            mediaGrid.querySelectorAll('[data-media-kind="gallery"]').forEach((card) => card.remove());
            getGalleryItems().forEach((item) => {
                const card = createMediaCard(item);
                card.classList.remove('is-entering');
                mediaGrid.insertBefore(card, mediaAddCard);
            });
        };

        const setMediaUploadState = (isUploading, fileCount = 0) => {
            if (!mediaAddCard || !mediaAddButton || !mediaAddLabel) return;

            mediaAddCard.classList.toggle('is-uploading', isUploading);
            mediaAddButton.disabled = isUploading;
            mediaAddButton.setAttribute('aria-busy', isUploading ? 'true' : 'false');

            if (mediaAddIcon) {
                mediaAddIcon.className = isUploading ? 'fa-solid fa-spinner' : 'fa-solid fa-plus';
            }

            mediaAddLabel.textContent = isUploading
                ? (fileCount > 1 ? `Завантаження ${fileCount} фото` : 'Завантаження фото')
                : 'Додати фото';
        };

        const setAvatarUploadState = (isUploading) => {
            if (!mediaAvatarCard || !mediaAvatarButton || !mediaAvatarLabel) return;

            mediaAvatarCard.classList.toggle('is-uploading', isUploading);
            mediaAvatarButton.disabled = isUploading;
            mediaAvatarButton.setAttribute('aria-busy', isUploading ? 'true' : 'false');
            mediaAvatarLabel.textContent = isUploading ? 'Завантаження аватарки' : (mediaLogoInput?.value ? 'Оновити аватарку' : 'Додати аватарку');

            const icon = mediaAvatarCard.querySelector('.pro-profile-media-add-card__icon i');
            if (icon) {
                icon.className = isUploading ? 'fa-solid fa-spinner' : 'fa-regular fa-user';
            }
        };

        const renderAssetCard = (kind, rawUrl, publicUrl, options = {}) => {
            if (!mediaGrid) return;

            mediaGrid.querySelector(`[data-media-kind="${kind}"]`)?.remove();
            if (!rawUrl || !publicUrl) return;

            const card = createAssetCard({
                kind,
                rawUrl,
                publicUrl,
                alt: options.alt || 'Медіа профілю',
                badge: options.badge || 'Медіа',
                removeLabel: options.removeLabel || 'Видалити',
            });

            const insertBeforeNode = kind === 'banner' ? mediaAvatarCard : mediaAddCard;
            mediaGrid.insertBefore(card, insertBeforeNode || null);
        };

        const syncMediaFromPayload = (mediaPayload) => {
            if (!mediaPayload) return;

            syncProfileFieldValue('logo_url', mediaPayload.logo_url || '');
            syncProfileFieldValue('banner_url', mediaPayload.banner_url || '');

            if (Array.isArray(mediaPayload.gallery_items)) {
                setGalleryItems(mediaPayload.gallery_items, { markCommitted: true });
                renderGalleryCards();
            } else if (Array.isArray(mediaPayload.gallery)) {
                setGalleryItems(mediaPayload.gallery, { markCommitted: true });
                renderGalleryCards();
            }

            renderAssetCard('banner', mediaPayload.banner_url || '', mediaPayload.banner_public_url || '', {
                alt: 'Обкладинка профілю',
                badge: 'Обкладинка',
                removeLabel: 'Видалити обкладинку',
            });
            renderAssetCard('logo', mediaPayload.logo_url || '', mediaPayload.logo_public_url || '', {
                alt: 'Аватарка профілю',
                badge: 'Аватарка',
                removeLabel: 'Видалити аватарку',
            });

            if (mediaAvatarLabel) {
                mediaAvatarLabel.textContent = mediaPayload.logo_url ? 'Оновити аватарку' : 'Додати аватарку';
            }
        };

        const commitGalleryItems = async () => submitProfileForm({ currentNames: new Set(['gallery_urls']) });

        const uploadGalleryFiles = async () => {
            if (!mediaFileInput?.files?.length) return;

            const selectedFiles = Array.from(mediaFileInput.files);
            setMediaUploadState(true, selectedFiles.length);

            const payload = await submitProfileForm({ currentNames: new Set(['gallery_files[]']) });

            if (payload?.profile?.media) {
                syncMediaFromPayload(payload.profile.media);
                mediaFileInput.value = '';
            }

            setMediaUploadState(false);
        };

        const uploadAvatarFile = async () => {
            if (!mediaAvatarInput?.files?.length) return;

            setAvatarUploadState(true);
            const payload = await submitProfileForm({ currentNames: new Set(['logo_file']) });

            if (payload?.profile?.media) {
                syncMediaFromPayload(payload.profile.media);
                mediaAvatarInput.value = '';
            }

            setAvatarUploadState(false);
        };

        const removeMediaCard = (card, callback) => {
            if (!card) {
                callback?.();
                return;
            }

            card.classList.add('is-removing');
            window.setTimeout(() => {
                callback?.();
                card.remove();
            }, 180);
        };

        const closeMediaMenus = (exceptCard = null) => {
            mediaGrid?.querySelectorAll('[data-media-card][data-media-kind="gallery"]').forEach((card) => {
                if (exceptCard && card === exceptCard) {
                    return;
                }

                card.classList.remove('is-menu-open');
                card.querySelector('[data-media-menu]')?.setAttribute('hidden', 'hidden');
                card.querySelector('[data-media-menu-trigger]')?.setAttribute('aria-expanded', 'false');
            });
        };

        const openMediaMenu = (card) => {
            if (!card) return;

            closeMediaMenus(card);
            card.classList.add('is-menu-open');
            card.querySelector('[data-media-menu]')?.removeAttribute('hidden');
            card.querySelector('[data-media-menu-trigger]')?.setAttribute('aria-expanded', 'true');
        };

        const toggleGalleryItemVisibility = async (rawUrl) => {
            const nextItems = getGalleryItems().map((item) => (
                item.raw === rawUrl
                    ? { ...item, visible: !item.visible }
                    : item
            ));

            setGalleryItems(nextItems);
            renderGalleryCards();

            const payload = await commitGalleryItems();
            if (payload?.profile?.media) {
                syncMediaFromPayload(payload.profile.media);
            }
        };

        mediaAddButton?.addEventListener('click', () => {
            if (!mediaAddButton.disabled) {
                mediaFileInput?.click();
            }
        });

        mediaAvatarButton?.addEventListener('click', () => {
            if (!mediaAvatarButton.disabled) {
                mediaAvatarInput?.click();
            }
        });

        mediaFileInput?.addEventListener('change', uploadGalleryFiles);
        mediaAvatarInput?.addEventListener('change', uploadAvatarFile);
        renderGalleryCards();

        document.addEventListener('click', (event) => {
            if (!mediaManager.contains(event.target)) {
                closeMediaMenus();
            }
        });

        mediaGrid?.addEventListener('click', (event) => {
            const menuTrigger = event.target.closest('[data-media-menu-trigger]');
            if (menuTrigger) {
                const card = menuTrigger.closest('[data-media-card]');
                const isOpen = card?.classList.contains('is-menu-open');
                if (isOpen) {
                    closeMediaMenus();
                } else {
                    openMediaMenu(card);
                }
                return;
            }

            const toggleVisibilityButton = event.target.closest('[data-media-toggle-visibility]');
            if (toggleVisibilityButton) {
                closeMediaMenus();
                const rawUrl = toggleVisibilityButton.dataset.mediaToggleVisibility || '';
                if (!rawUrl) return;

                toggleGalleryItemVisibility(rawUrl);
                return;
            }

            const makeAvatarButton = event.target.closest('[data-media-make-avatar]');
            if (makeAvatarButton) {
                closeMediaMenus();
                const rawUrl = makeAvatarButton.dataset.mediaMakeAvatar || '';
                if (!rawUrl || !mediaLogoInput) return;

                mediaLogoInput.value = rawUrl;
                submitProfileForm({ currentNames: collectCurrentNames(mediaLogoInput) })
                    .then((payload) => {
                        if (payload?.profile?.media) {
                            syncMediaFromPayload(payload.profile.media);
                        }
                    });
                return;
            }

            const removeGalleryButton = event.target.closest('[data-media-remove-gallery]');
            if (removeGalleryButton) {
                closeMediaMenus();
                const rawUrl = removeGalleryButton.dataset.mediaRemoveGallery || '';
                const card = removeGalleryButton.closest('[data-media-card]');
                const nextItems = getGalleryItems().filter((item) => item.raw !== rawUrl);
                setGalleryItems(nextItems);
                removeMediaCard(card, () => {
                    commitGalleryItems()
                        .then((payload) => {
                            if (payload?.profile?.media) {
                                syncMediaFromPayload(payload.profile.media);
                            }
                        });
                });
                return;
            }

            const removeButton = event.target.closest('[data-media-remove]');
            if (!removeButton) return;

            const type = removeButton.dataset.mediaRemove;
            const input = type === 'logo' ? mediaLogoInput : mediaBannerInput;
            if (!input) return;

            input.value = '';
            removeMediaCard(removeButton.closest('[data-media-card]'), () => {
                submitProfileForm({ currentNames: collectCurrentNames(input) })
                    .then((payload) => {
                        if (payload?.profile?.media) {
                            syncMediaFromPayload(payload.profile.media);
                        }
                    });
            });
        });
    }

    if (serviceEditor) {
        const serviceList = serviceEditor.querySelector('[data-service-list]');
        const serviceInputWrap = serviceEditor.querySelector('[data-service-entry-wrap]');
        const serviceInput = serviceEditor.querySelector('[data-service-entry]');
        const serviceSuggestions = serviceEditor.querySelector('[data-service-suggestions]');
        const serviceAddButton = serviceEditor.querySelector('[data-service-add]');
        let serviceBlurTimer = null;
        let serviceBlurLocked = false;

        const normalizeServiceName = (value) => value.replace(/\s+/g, ' ').trim();
        const getServiceNames = () => Array.from(serviceList?.querySelectorAll('input[name="service_names[]"]') ?? [])
            .map((input) => normalizeServiceName(input.value))
            .filter(Boolean);

        const hasServiceName = (name) => getServiceNames()
            .some((value) => value.toLocaleLowerCase('uk-UA') === name.toLocaleLowerCase('uk-UA'));

        const hideServiceSuggestions = () => {
            if (!serviceSuggestions) return;
            serviceSuggestions.hidden = true;
            serviceSuggestions.innerHTML = '';
        };

        const openServiceInput = () => {
            if (!serviceInputWrap || !serviceInput || !serviceAddButton) return;

            serviceBlurLocked = false;
            serviceInputWrap.hidden = false;
            serviceAddButton.hidden = true;
            serviceEditor.querySelector('[data-service-shell]')?.classList.add('is-focused');
            window.requestAnimationFrame(() => {
                serviceInput.focus({ preventScroll: true });
            });
        };

        const closeServiceInput = () => {
            if (!serviceInputWrap || !serviceInput || !serviceAddButton) return;

            serviceInput.value = '';
            serviceInputWrap.hidden = true;
            serviceAddButton.hidden = false;
            serviceEditor.querySelector('[data-service-shell]')?.classList.remove('is-focused');
            hideServiceSuggestions();
        };

        const getMatchingServiceSuggestions = (query) => {
            const categoryId = Number(category?.value || 0);
            if (!categoryId) return [];

            const normalizedQuery = normalizeServiceName(query).toLocaleLowerCase('uk-UA');

            return serviceCandidates
                .filter((item) => Number(item.category_id) === categoryId)
                .map((item) => normalizeServiceName(String(item.name || '')))
                .filter(Boolean)
                .filter((name, index, all) => all.findIndex((item) => item.toLocaleLowerCase('uk-UA') === name.toLocaleLowerCase('uk-UA')) === index)
                .filter((name) => !hasServiceName(name))
                .filter((name) => normalizedQuery === '' || name.toLocaleLowerCase('uk-UA').includes(normalizedQuery))
                .slice(0, 8);
        };

        const renderServiceSuggestions = (query = '') => {
            if (!serviceSuggestions) return;

            const suggestions = getMatchingServiceSuggestions(query);
            if (!suggestions.length) {
                hideServiceSuggestions();
                return;
            }

            serviceSuggestions.innerHTML = '';

            suggestions.forEach((name) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'pro-profile-service-suggestion';
                button.dataset.serviceSuggestion = name;
                button.textContent = name;
                serviceSuggestions.appendChild(button);
            });

            serviceSuggestions.hidden = false;
        };

        const createServiceChip = (name) => {
            const chip = document.createElement('span');
            chip.className = 'pro-profile-service-tag is-entering';
            chip.dataset.serviceChip = '';

            const text = document.createElement('span');
            text.textContent = name;

            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'pro-profile-service-tag__remove';
            remove.dataset.serviceRemove = '';
            remove.setAttribute('aria-label', `Видалити ${name}`);
            remove.innerHTML = '<i class="fa-solid fa-xmark" aria-hidden="true"></i>';

            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'service_names[]';
            hidden.value = name;

            chip.append(text, remove, hidden);
            return chip;
        };

        const commitServiceNames = async () => {
            await submitProfileForm({ currentNames: new Set(['service_names[]']) });
        };

        const addServiceName = async (rawValue) => {
            if (!serviceList || !serviceInput || !serviceInputWrap) return;

            const name = normalizeServiceName(rawValue);

            if (serviceBlurTimer) {
                window.clearTimeout(serviceBlurTimer);
                serviceBlurTimer = null;
            }

            hideServiceSuggestions();

            if (!name) {
                closeServiceInput();
                return;
            }

            if (!category?.value) {
                setProfileStatus('Спочатку оберіть категорію, щоб додати послугу.', 'error');
                category?.focus();
                return;
            }

            if (hasServiceName(name)) {
                closeServiceInput();
                return;
            }

            const nextChip = createServiceChip(name);
            serviceList.insertBefore(nextChip, serviceInputWrap);
            requestAnimationFrame(() => {
                nextChip.classList.remove('is-entering');
            });
            closeServiceInput();
            await commitServiceNames();
        };

        serviceAddButton?.addEventListener('click', () => {
            openServiceInput();
            renderServiceSuggestions('');
        });

        serviceInput?.addEventListener('focus', () => {
            serviceBlurLocked = false;
            serviceEditor.querySelector('[data-service-shell]')?.classList.add('is-focused');
            renderServiceSuggestions(serviceInput.value);
        });

        serviceInput?.addEventListener('input', () => {
            renderServiceSuggestions(serviceInput.value);
        });

        serviceInput?.addEventListener('keydown', async (event) => {
            if (event.key === 'Enter' || event.key === ',') {
                event.preventDefault();
                await addServiceName(serviceInput.value);
                return;
            }

            if (event.key === 'Backspace' && !serviceInput.value.trim()) {
                const serviceChips = Array.from(serviceList?.querySelectorAll('[data-service-chip]') ?? []);
                const lastChip = serviceChips[serviceChips.length - 1];
                if (!lastChip) return;

                event.preventDefault();
                lastChip.classList.add('is-removing');
                await new Promise((resolve) => window.setTimeout(resolve, 180));
                lastChip.remove();
                await commitServiceNames();
            }

            if (event.key === 'Escape') {
                event.preventDefault();
                closeServiceInput();
            }
        });

        serviceInput?.addEventListener('blur', async () => {
            if (serviceBlurTimer) {
                window.clearTimeout(serviceBlurTimer);
            }

            serviceBlurTimer = window.setTimeout(async () => {
                serviceBlurTimer = null;

                if (serviceBlurLocked) {
                    serviceBlurLocked = false;
                    return;
                }

                await addServiceName(serviceInput.value);
            }, 120);
        });

        serviceList?.addEventListener('click', async (event) => {
            const removeButton = event.target.closest('[data-service-remove]');
            if (!removeButton) return;

            const chip = removeButton.closest('[data-service-chip]');
            if (!chip) return;

            chip.classList.add('is-removing');
            await new Promise((resolve) => window.setTimeout(resolve, 180));
            chip.remove();
            await commitServiceNames();
            renderServiceSuggestions(serviceInput?.value || '');
        });

        serviceSuggestions?.addEventListener('mousedown', async (event) => {
            const suggestionButton = event.target.closest('[data-service-suggestion]');
            if (!suggestionButton) return;

            event.preventDefault();
            serviceBlurLocked = true;
            await addServiceName(suggestionButton.dataset.serviceSuggestion || '');
            serviceAddButton?.focus({ preventScroll: true });
        });

        category?.addEventListener('change', () => {
            if (serviceInputWrap?.hidden) return;
            renderServiceSuggestions(serviceInput?.value || '');
        });

        serviceEditor.addEventListener('mousedown', (event) => {
            if (event.target.closest('[data-service-remove]')) {
                serviceBlurLocked = true;
            }
        });

        serviceEditor.querySelector('[data-service-shell]')?.addEventListener('click', (event) => {
            if (event.target.closest('[data-service-remove], [data-service-add], [data-service-entry], [data-service-suggestion]')) {
                return;
            }

            openServiceInput();
        });

        serviceInput?.addEventListener('blur', () => {
            if (!serviceBlurLocked) {
                serviceEditor.querySelector('[data-service-shell]')?.classList.remove('is-focused');
            }
        });
    }
};

const isProAccountUrl = (url) => url.origin === window.location.origin && url.pathname === PRO_ACCOUNT_PATH;

const buildGetFormUrl = (form) => {
    const url = new URL(form.getAttribute('action') || window.location.href, window.location.origin);
    url.search = '';

    const formData = new FormData(form);
    formData.forEach((value, key) => {
        if (typeof value === 'string') {
            url.searchParams.append(key, value);
        }
    });

    return url;
};

const scrollToHash = (hash) => {
    if (!hash) {
        window.scrollTo({ top: 0, behavior: 'auto' });
        return;
    }

    const targetId = decodeURIComponent(hash.replace(/^#/, ''));
    const target = document.getElementById(targetId);

    if (target) {
        // A collapsed mobile accordion section must open before we scroll to it.
        target.closest('.pro-profile-section.is-collapsed')?.classList.remove('is-collapsed');
        target.scrollIntoView({ block: 'start', behavior: 'auto' });
        return;
    }

    window.location.hash = hash;
};

const replaceProAccountShell = (nextShell, targetUrl, historyMode) => {
    const currentShell = document.querySelector('[data-pro-account-shell]');
    if (!currentShell) return;

    currentShell.replaceWith(nextShell);
    document.body.classList.remove('is-account-menu-open', 'is-menu-open');

    if (historyMode === 'push') {
        window.history.pushState({ proAccount: true }, '', targetUrl.toString());
    } else {
        window.history.replaceState({ proAccount: true }, '', targetUrl.toString());
    }

    initProAccountPage(nextShell);
    scrollToHash(targetUrl.hash);
};

const navigateProAccount = async (target, { historyMode = 'push' } = {}) => {
    const targetUrl = target instanceof URL ? target : new URL(target, window.location.origin);

    if (!isProAccountUrl(targetUrl)) {
        window.location.assign(targetUrl.toString());
        return;
    }

    const currentUrl = new URL(window.location.href);
    if (targetUrl.pathname === currentUrl.pathname && targetUrl.search === currentUrl.search && targetUrl.hash !== currentUrl.hash) {
        window.history[historyMode === 'push' ? 'pushState' : 'replaceState']({ proAccount: true }, '', targetUrl.toString());
        scrollToHash(targetUrl.hash);
        return;
    }

    // Перед заміною shell доберігаємо все, що юзер щойно ввів у форму профілю.
    if (proProfileFlushPending) {
        try {
            await proProfileFlushPending();
        } catch (error) {
            // Збереження не вдалось — навігацію не блокуємо, beforeunload підстрахує.
        }
    }

    proAccountNavigationController?.abort();
    proAccountNavigationController = new AbortController();
    const { signal } = proAccountNavigationController;
    const shell = document.querySelector('[data-pro-account-shell]');

    shell?.classList.add('is-loading');
    shell?.setAttribute('aria-busy', 'true');

    try {
        const response = await fetch(targetUrl.toString(), {
            headers: {
                Accept: 'text/html',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            signal,
        });

        if (!response.ok) {
            throw new Error(`Navigation request failed: ${response.status}`);
        }

        const html = await response.text();
        const parser = new DOMParser();
        const nextDocument = parser.parseFromString(html, 'text/html');
        const nextShell = nextDocument.querySelector('[data-pro-account-shell]');
        const nextTitle = nextDocument.querySelector('title')?.textContent?.trim();

        if (!nextShell) {
            throw new Error('Pro account shell missing in navigation response.');
        }

        if (nextTitle) {
            document.title = nextTitle;
        }

        replaceProAccountShell(nextShell, targetUrl, historyMode);
    } catch (error) {
        if (signal.aborted) return;
        window.location.assign(targetUrl.toString());
    } finally {
        shell?.classList.remove('is-loading');
        shell?.removeAttribute('aria-busy');
    }
};

export const initProAccountAjaxNavigation = (root = document) => {
    if (!document.body.classList.contains('page-pro-account')) return;

    const shell = root instanceof Element && root.matches('[data-pro-account-shell]')
        ? root
        : document.querySelector('[data-pro-account-shell]');

    if (!shell || shell.dataset.proAccountAjaxBound === 'true') return;
    if (shell.closest('[data-pro-account-livewire]')) return;

    shell.dataset.proAccountAjaxBound = 'true';

    shell.addEventListener('click', (event) => {
        if (event.defaultPrevented || event.button !== 0) return;
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

        const link = event.target.closest('a[href]');
        if (!link) return;
        if (link.target && link.target !== '_self') return;
        if (link.hasAttribute('download')) return;

        const url = new URL(link.href, window.location.origin);
        if (!isProAccountUrl(url)) return;

        event.preventDefault();
        navigateProAccount(url, { historyMode: 'push' });
    });

    shell.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) return;

        const method = (form.getAttribute('method') || 'get').toLowerCase();
        if (method !== 'get') return;

        const url = buildGetFormUrl(form);
        if (!isProAccountUrl(url)) return;

        event.preventDefault();
        navigateProAccount(url, { historyMode: 'push' });
    });

    if (!proAccountPopstateBound) {
        window.addEventListener('popstate', () => {
            if (!document.body.classList.contains('page-pro-account')) return;
            if (window.location.pathname !== PRO_ACCOUNT_PATH) return;

            navigateProAccount(new URL(window.location.href), { historyMode: 'replace' });
        });

        proAccountPopstateBound = true;
    }
};

const initProAccountLivewireSupport = () => {
    if (proAccountLivewireHooksBound) return;

    document.addEventListener('pro-account-scroll-to', (event) => {
        const targetId = event?.detail?.id;
        if (!targetId) return;

        window.requestAnimationFrame(() => {
            scrollToHash(`#${targetId}`);
        });
    });

    document.addEventListener('livewire:init', () => {
        if (!window.Livewire?.hook) return;

        window.Livewire.hook('commit', ({ succeed }) => {
            succeed(() => {
                if (!document.body.classList.contains('page-pro-account')) return;
                if (!document.querySelector('[data-pro-account-livewire]')) return;

                document.querySelectorAll('[data-pro-overview-analytics].is-loading').forEach((analyticsCard) => {
                    setProLoadingState(analyticsCard, false);
                });

                initProAccountPage(document);

                // Belt-and-suspenders: the first period change right after load
                // has a late re-render whose chart can be left at Chart.js's
                // 300×150 default (blank). Force-resize every overview chart to
                // its container across a couple of settle points after the morph.
                [120, 400, 800].forEach((delay) => {
                    window.setTimeout(() => {
                        document.querySelectorAll('[data-pro-overview-chart]').forEach(resizeChartToContainer);
                    }, delay);
                });

                if (pendingProAccountScrollId) {
                    scrollToHash(`#${pendingProAccountScrollId}`);
                    pendingProAccountScrollId = null;
                }
            });
        });
    });

    proAccountLivewireHooksBound = true;
};

const initProProfileSectionAccordion = (shell) => {
    const sections = Array.from(shell.querySelectorAll('.pro-profile-form-card .pro-profile-section'));
    if (!sections.length) return;

    sections.forEach((section, index) => {
        if (section.dataset.proSectionAccordionBound === 'true') return;
        section.dataset.proSectionAccordionBound = 'true';

        const head = section.querySelector('.pro-profile-section__head');
        if (!head) return;

        // Collapsed by default on mobile (except the first section) so the
        // form reads as a short list of topics instead of one long page.
        // The smooth open/close itself is pure CSS (max-height transition).
        if (isMobileProAccountViewport() && index > 0 && !section.contains(document.activeElement)) {
            section.classList.add('is-collapsed');
        }

        head.addEventListener('click', () => {
            if (!isMobileProAccountViewport()) return;
            section.classList.toggle('is-collapsed');
        });
    });
};

export const initProAccountPage = (root = document) => {
    if (!document.body.classList.contains('page-pro-account')) return;

    const shell = root instanceof Element && root.matches('[data-pro-account-shell]')
        ? root
        : document.querySelector('[data-pro-account-shell]');

    if (!shell) return;

    initProAccountClientTabs(shell);
    initProfileVisualSeeds(shell);
    initProReviewAvatarFallbacks(shell);
    initProProfileImageFallbacks(shell);
    initProAnalyticsCharts(shell);
    initAccountSidebarMenu(shell);
    initSearchForms(shell);
    initProOverviewAnalytics(shell);
    initProAccountWorkspace(shell);
    initProAccountAjaxNavigation(shell);
    initProProfileSectionAccordion(shell);
};

initProHeader();
initProPricing();
initProAccountLivewireSupport();
initProAnalyticsPeriodSwitcher();
initProAccountPage(document);

// Копіювання сніпетів віджета в кабінеті. Делегований обробник на document,
// щоб кнопки працювали й після Livewire-морфів DOM.
const initWidgetSnippetCopy = () => {
    document.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-widget-copy]');
        if (!button) return;

        const target = document.getElementById(button.getAttribute('data-widget-copy-target') || '');
        if (!target) return;

        const text = target.value || target.textContent || '';
        let copied = false;

        try {
            await navigator.clipboard.writeText(text);
            copied = true;
        } catch (error) {
            // Fallback для http/старих браузерів: виділити й execCommand.
            target.focus();
            target.select();
            try {
                copied = document.execCommand('copy');
            } catch (fallbackError) {
                copied = false;
            }
            window.getSelection()?.removeAllRanges();
        }

        const label = button.querySelector('[data-widget-copy-label]');
        if (!label) return;

        label.textContent = copied ? 'Скопійовано!' : 'Не вдалося';
        button.classList.toggle('is-copied', copied);
        window.setTimeout(() => {
            label.textContent = 'Копіювати';
            button.classList.remove('is-copied');
        }, 2000);
    });
};

initWidgetSnippetCopy();

// --- Плавне розкриття інструкції «Як встановити» ---
// Той самий сучасний підхід, що й FAQ: grid-template-rows 0fr↔1fr анімує
// висоту auto без JS-виміру. <details> лишається для доступності.
const initWidgetGuideAnimation = () => {
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    document.addEventListener('click', (event) => {
        const summary = event.target.closest('[data-widget-guide] > summary');
        if (!summary) return;
        event.preventDefault();

        const details = summary.parentElement;
        const body = details.querySelector('[data-widget-guide-body]');
        if (!body) return;

        if (details.open) {
            if (reduceMotion) { details.open = false; return; }
            details.classList.remove('is-open');
            const onEnd = (e) => {
                if (e.propertyName !== 'grid-template-rows') return;
                body.removeEventListener('transitionend', onEnd);
                if (!details.classList.contains('is-open')) details.open = false;
            };
            body.addEventListener('transitionend', onEnd);
            return;
        }

        details.open = true;
        if (reduceMotion) { details.classList.add('is-open'); return; }
        requestAnimationFrame(() => requestAnimationFrame(() => details.classList.add('is-open')));
    });
};

initWidgetGuideAnimation();

// --- Плаваючий віджет: вибір кута оновлює мокап і код-сніпет ---
const initWidgetFloatCorner = () => {
    document.addEventListener('click', (event) => {
        const btn = event.target.closest('[data-widget-float-corner]');
        if (!btn) return;

        const option = btn.closest('[data-widget-float-option]');
        if (!option) return;

        const corner = btn.dataset.widgetFloatCorner || 'bottom-right';

        option.querySelectorAll('[data-widget-float-corner]').forEach((node) => {
            const active = node === btn;
            node.classList.toggle('is-active', active);
            node.setAttribute('aria-pressed', active ? 'true' : 'false');
        });

        const mock = option.querySelector('[data-widget-float-mock]');
        if (mock) mock.dataset.corner = corner;

        const snippet = option.querySelector('[data-widget-float-snippet]');
        if (snippet) {
            const src = snippet.dataset.baseSrc || '';
            snippet.value = '<!-- DOVIRA плаваючий віджет -->\n'
                + '<script src="' + src + '" data-position="' + corner + '" async><\/script>';
        }
    });
};

initWidgetFloatCorner();

// --- Перемикач режиму кнопки звʼязку в редакторі профілю ---
// Делеговано на document: переживає Livewire-перемальовування форми.
document.addEventListener('change', (event) => {
    const radio = event.target.closest('[data-cta-mode-radio]');
    if (!radio) return;
    const field = radio.closest('[data-cta-mode-field]');
    if (!field) return;
    const isLeadForm = radio.value === 'lead_form';
    const urlField = field.querySelector('[data-cta-url-field]');
    const leadNote = field.querySelector('[data-cta-lead-note]');
    if (urlField) urlField.hidden = isLeadForm;
    if (leadNote) leadNote.hidden = !isLeadForm;
    field.querySelectorAll('.pro-cta-mode-switch__option').forEach((option) => {
        option.classList.toggle('is-active', option.contains(radio));
    });
});

// --- «Потребує уваги»: клік по всьому рядку веде туди ж, куди шеврон ---
document.addEventListener('click', (event) => {
    const card = event.target.closest('.pro-overview-attention-card');
    if (!card) return;
    if (event.target.closest('button, a')) return;
    card.querySelector('.pro-overview-attention-card__action')?.click();
});
