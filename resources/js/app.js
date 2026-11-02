import Chart from 'chart.js/auto';
import { getRelativePosition } from 'chart.js/helpers';

Chart.defaults.font.family = "'Helvetica Neue', Helvetica, Arial, system-ui, sans-serif";
Chart.defaults.font.size = 11;
Chart.defaults.color = '#5c6873';
Chart.defaults.maintainAspectRatio = false;

const compact = (value) => {
    const absolute = Math.abs(value);
    const sign = value < 0 ? '−' : '';

    if (absolute >= 1e6) {
        return `${sign}$${(absolute / 1e6).toFixed(2)}M`;
    }

    if (absolute >= 1e3) {
        return `${sign}$${Math.round(absolute / 1e3)}k`;
    }

    return `${sign}$${Math.round(absolute)}`;
};

const grid = { color: '#f0f1f2' };

/** The index of the Projected bar whose column (its width, the chart's full height) holds the point, or null. */
const projectionBarAt = (chart, x, y) => {
    const { left, right, top, bottom } = chart.chartArea;

    if (x < left || x > right || y < top || y > bottom) {
        return null;
    }

    const datasetIndex = chart.data.datasets.findIndex((dataset) => dataset.label === 'Projected');
    const index = chart.getDatasetMeta(datasetIndex).data.findIndex((bar) => {
        const { x: middle, width } = bar.getProps(['x', 'width'], true);

        return Math.abs(x - middle) <= width / 2;
    });

    return index === -1 ? null : index;
};

/*
 * Overview's projected billing is set a quarter at a time. Drag a grey bar up
 * or down and the quarter's total follows the pointer, in steps of $1,000,
 * spread evenly over its months; let go and a bubbling "projection-set" event
 * carries { quarter, total }. A click without dragging raises
 * "projection-open" with the quarter, its total and where it was clicked, so
 * the page can offer a box to type the total into.
 */
const projectionDrag = ({ quarters, keys }) => {
    let drag = null;
    const listeners = {};

    const projectedOf = (chart) => chart.data.datasets.find((dataset) => dataset.label === 'Projected').data;

    const quarterTotal = (chart, quarter) => {
        const values = projectedOf(chart).filter((value, index) => quarters[index] === quarter && value !== null);

        return values.length ? values.reduce((sum, value) => sum + value, 0) : null;
    };

    const spread = (chart, quarter, total) => {
        const data = projectedOf(chart);

        quarters.forEach((barQuarter, index) => {
            if (barQuarter === quarter) {
                data[index] = (total * keys[index].length) / 3;
            }
        });
    };

    return {
        id: 'projectionDrag',

        afterInit(chart) {
            const canvas = chart.canvas;
            const scale = chart.options.scales.y;

            const valueAt = (y) => {
                const { top, bottom } = chart.chartArea;
                const value = chart.scales.y.getValueForPixel(Math.min(Math.max(y, top), bottom));

                return Math.max(0, Math.round(value / 1000) * 1000);
            };

            listeners.pointerdown = (event) => {
                const { x, y } = getRelativePosition(event, chart);
                const index = projectionBarAt(chart, x, y);

                chart.$projectionHandled = index !== null;

                if (index === null) {
                    return;
                }

                // Hold the axis still while dragging, so the bar tracks the pointer.
                scale.min = chart.scales.y.min;
                scale.max = chart.scales.y.max;
                drag = { index, startY: y, moved: false, total: null };
                canvas.setPointerCapture(event.pointerId);
                event.preventDefault();
            };

            listeners.pointermove = (event) => {
                const { y } = getRelativePosition(event, chart);

                if (!drag || (!drag.moved && Math.abs(y - drag.startY) < 4)) {
                    return;
                }

                drag.moved = true;
                drag.total = (valueAt(y) * 3) / keys[drag.index].length;
                spread(chart, quarters[drag.index], drag.total);
                chart.tooltip.setActiveElements([], { x: 0, y: 0 });
                chart.update('none');
            };

            listeners.pointerup = (event) => {
                if (!drag) {
                    return;
                }

                const { index, moved, total } = drag;
                const quarter = quarters[index];
                const { x, y } = getRelativePosition(event, chart);

                drag = null;
                scale.min = undefined;
                scale.max = undefined;

                if (moved) {
                    canvas.dispatchEvent(new CustomEvent('projection-set', { bubbles: true, detail: { quarter, total } }));

                    return;
                }

                canvas.dispatchEvent(
                    new CustomEvent('projection-open', {
                        bubbles: true,
                        detail: { quarter, total: quarterTotal(chart, quarter), x, y },
                    }),
                );
            };

            Object.entries(listeners).forEach(([type, listener]) => canvas.addEventListener(type, listener));
        },

        afterDatasetsDraw(chart) {
            const datasetIndex = chart.data.datasets.findIndex((dataset) => dataset.label === 'Projected');
            const bars = chart.getDatasetMeta(datasetIndex).data;
            const values = projectedOf(chart);
            const baseline = chart.scales.y.getPixelForValue(0);
            const { ctx } = chart;

            // A grip on top of each grey bar, or a dashed stub where none is set yet, so it reads as something to drag.
            ctx.save();
            bars.forEach((bar, index) => {
                const { x, y, width } = bar.getProps(['x', 'y', 'width'], true);
                const top = values[index] === null ? baseline - 22 : y;

                if (values[index] === null) {
                    ctx.setLineDash([3, 3]);
                    ctx.strokeStyle = '#9aa4ae';
                    ctx.strokeRect(x - width / 2 + 1, top, width - 2, baseline - top);
                    ctx.setLineDash([]);
                }

                ctx.fillStyle = '#5c6873';
                [-3, 0, 3].forEach((offset) => ctx.fillRect(x - 7, top + 8 + offset, 14, 1.5));
            });
            ctx.restore();

            if (!drag?.moved) {
                return;
            }

            const { x, y } = bars[drag.index].getProps(['x', 'y'], true);

            ctx.save();
            ctx.font = `600 11px ${Chart.defaults.font.family}`;
            ctx.fillStyle = '#00263E';
            ctx.textAlign = 'center';
            ctx.fillText(`Q${quarters[drag.index]} ${compact(drag.total)}`, x, y - 6);
            ctx.restore();
        },

        beforeDestroy(chart) {
            Object.entries(listeners).forEach(([type, listener]) => chart.canvas.removeEventListener(type, listener));
        },
    };
};

const charts = {
    activity: ({ labels, payments, hours }) => ({
        data: {
            labels,
            datasets: [
                {
                    type: 'bar',
                    label: 'Payments',
                    data: payments,
                    backgroundColor: 'rgba(0,38,62,.08)',
                    borderColor: 'rgba(0,38,62,.25)',
                    borderWidth: 1,
                    yAxisID: 'y',
                },
                {
                    type: 'line',
                    label: 'Hours',
                    data: hours,
                    borderColor: '#a5573c',
                    backgroundColor: '#a5573c',
                    pointRadius: 2,
                    tension: 0,
                    yAxisID: 'hours',
                },
            ],
        },
        options: {
            interaction: { mode: 'index', intersect: false },
            plugins: { legend: { position: 'bottom' } },
            scales: {
                x: { grid: { display: false } },
                y: { grid, border: { display: false }, ticks: { callback: compact }, title: { display: true, text: 'Payments' } },
                hours: { position: 'right', grid: { display: false }, border: { display: false }, title: { display: true, text: 'Hours' } },
            },
        },
    }),

    overview: ({ labels, actual, cost, profit, projected = [], projectedCost = [], quarters = [], keys = [], editable = false }) => ({
        data: {
            labels,
            datasets: [
                { type: 'line', label: 'Profit', data: profit, borderColor: '#2f8a67', backgroundColor: '#2f8a67', borderWidth: 2, pointRadius: 3, tension: 0, order: 0 },
                ...(projectedCost.some((value) => value !== null)
                    ? [{ type: 'line', label: 'Projected cost', data: projectedCost, borderColor: '#c4694a', backgroundColor: '#fff', borderDash: [5, 4], borderWidth: 1.5, pointRadius: 4, pointBorderWidth: 1.5, spanGaps: true, tension: 0, order: 0 }]
                    : []),
                { type: 'bar', label: 'Billed', data: actual, backgroundColor: '#00263E', stack: 'billed', order: 1 },
                { type: 'bar', label: 'Projected', data: [...projected], backgroundColor: '#d3d8dd', hoverBackgroundColor: '#bcc3ca', stack: 'projected', order: 1 },
                { type: 'bar', label: 'Cost', data: cost, backgroundColor: '#c4694a', stack: 'cost', order: 1 },
            ],
        },
        plugins: editable ? [projectionDrag({ quarters, keys })] : [],
        options: {
            datasets: { bar: { barPercentage: 1, categoryPercentage: 0.66 } },
            onClick: (event, elements, chart) => {
                if (chart.$projectionHandled) {
                    chart.$projectionHandled = false;

                    return;
                }

                emitClick(chart, elements, actual.map((value) => value !== null));
            },
            onHover: (event, elements, chart) => {
                if (editable && projectionBarAt(chart, event.x, event.y) !== null) {
                    chart.canvas.style.cursor = 'ns-resize';

                    return;
                }

                pointerOver(event, elements, chart, actual.map((value) => value !== null));
            },
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'bottom' },
                tooltip: { callbacks: { label: (item) => (item.raw === null ? null : `${item.dataset.label}: ${compact(item.raw)}`) } },
            },
            scales: {
                x: { grid: { display: false }, stacked: true },
                y: { grid, border: { display: false }, ticks: { callback: compact } },
            },
        },
    }),

    ring: ({ labels, values, colors }) => ({
        type: 'doughnut',
        data: { labels, datasets: [{ data: values, backgroundColor: colors, borderWidth: 0 }] },
        options: {
            cutout: '68%',
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: (item) => `${item.label}: ${compact(item.raw)}` } },
            },
        },
    }),

    cumulative: ({ labels, billed, contract }) => ({
        type: 'line',
        data: {
            labels,
            datasets: [
                {
                    label: 'Billed to date',
                    data: billed,
                    borderColor: '#3f7d4f',
                    backgroundColor: 'rgba(47,138,103,.10)',
                    fill: true,
                    pointRadius: 0,
                    tension: 0.15,
                },
                {
                    label: 'Contract',
                    data: labels.map(() => contract),
                    borderColor: '#85754E',
                    borderDash: [4, 4],
                    borderWidth: 1,
                    pointRadius: 0,
                },
            ],
        },
        options: {
            interaction: { mode: 'index', intersect: false },
            plugins: { legend: { position: 'bottom' } },
            scales: {
                x: { grid: { display: false } },
                y: { grid, border: { display: false }, ticks: { callback: compact } },
            },
        },
    }),
};

// A chart still animating when wire:navigate swaps the page would draw into a removed canvas.
document.addEventListener('livewire:navigating', () => {
    Object.values(Chart.instances).forEach((chart) => chart.destroy());
});

/*
 * The chart is held in a closure, not on the Alpine component. Alpine wraps
 * its data in a reactive Proxy, and a chart destroyed through that proxy asks
 * Chart.js's shared animator to forget the proxy rather than the chart, so the
 * dead chart stayed in the animator. Its next tick threw on the missing canvas
 * and stalled the animator, which left every chart drawn after it blank (switching
 * Overview's quarters quickly).
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('chart', (kind, payload) => {
        let instance = null;

        return {
            init() {
                this.render(payload);
            },

            render(next) {
                instance?.destroy();
                instance = new Chart(this.$refs.canvas, charts[kind](next));
            },

            destroy() {
                instance?.destroy();
                instance = null;
            },
        };
    });
});

/*
 * Month End and Extras. A click on a month is raised as a
 * bubbling "chart-click" DOM event carrying the month's index, so the page can
 * hand it to Livewire with x-on:chart-click.
 */
const moneyWhole = new Intl.NumberFormat('en-CA', { style: 'currency', currency: 'CAD', currencyDisplay: 'narrowSymbol', maximumFractionDigits: 0 });

const compactFine = (value) => {
    const absolute = Math.abs(value);
    const sign = value < 0 ? '−' : '';

    if (absolute >= 1e6) {
        return `${sign}$${(absolute / 1e6).toFixed(2)}M`;
    }

    if (absolute >= 1e3) {
        return `${sign}$${(absolute / 1e3).toFixed(absolute >= 1e4 ? 0 : 1)}k`;
    }

    return `${sign}$${Math.round(absolute)}`;
};

const emitClick = (chart, elements, clickable) => {
    const index = elements[0]?.index;

    if (index === undefined || (clickable && !clickable[index])) {
        return;
    }

    chart.canvas.dispatchEvent(new CustomEvent('chart-click', { bubbles: true, detail: { index } }));
};

const pointerOver = (event, elements, chart, clickable) => {
    const index = elements[0]?.index;

    chart.canvas.style.cursor = index !== undefined && (!clickable || clickable[index]) ? 'pointer' : 'default';
};

const revenueLabels = { actual: 'Billed', runrate: 'At this pace' };

Object.assign(charts, {
    monthEndRevenue: ({ labels, revenue, bases, prior, selected, clickable, spent }) => ({
        type: 'line',
        data: {
            labels,
            datasets: [
                {
                    label: 'Revenue',
                    data: revenue,
                    borderColor: '#00263E',
                    borderWidth: 2.5,
                    tension: 0,
                    segment: {
                        borderDash: (context) => (bases[context.p1DataIndex] !== 'actual' ? [5, 4] : undefined),
                        borderColor: (context) => (bases[context.p1DataIndex] !== 'actual' ? '#a9bccb' : '#00263E'),
                    },
                    pointRadius: revenue.map((value, index) => (index === selected ? 6 : 3)),
                    pointHoverRadius: 7,
                    pointBackgroundColor: revenue.map((value, index) => (index === selected ? '#0b3a5a' : bases[index] === 'actual' ? '#00263E' : '#fff')),
                    pointBorderColor: revenue.map((value, index) => (index === selected ? '#0b3a5a' : bases[index] === 'actual' ? '#00263E' : '#a9bccb')),
                },
                {
                    label: 'Prior year',
                    data: prior,
                    borderColor: '#5c6873',
                    borderWidth: 1.5,
                    borderDash: [4, 3],
                    pointRadius: 0,
                    spanGaps: true,
                },
                // Managers and admins only: staff time at cost, behind the lines.
                ...(spent
                    ? [
                          {
                              type: 'bar',
                              label: 'Spent',
                              data: spent,
                              backgroundColor: spent.map((value, index) => (index === selected ? '#d9a896' : '#efd9d0')),
                              borderRadius: 3,
                              maxBarThickness: 22,
                              order: 3,
                          },
                      ]
                    : []),
            ],
        },
        options: {
            interaction: { mode: 'index', intersect: false },
            onClick: (event, elements, chart) => emitClick(chart, elements, clickable),
            onHover: (event, elements, chart) => pointerOver(event, elements, chart, clickable),
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: (item) => {
                            if (item.raw === null) {
                                return null;
                            }

                            const label = item.datasetIndex === 0 ? (revenueLabels[bases[item.dataIndex]] ?? 'Billed') : item.dataset.label;

                            return `${label}: ${moneyWhole.format(item.raw)}`;
                        },
                    },
                },
            },
            scales: {
                x: { grid: { display: false }, ticks: { color: '#5c6873', font: { size: 11 } } },
                y: { beginAtZero: true, grid, border: { display: false }, ticks: { maxTicksLimit: 6, callback: compact } },
            },
        },
    }),

    extrasBars: ({ labels, titles, values, selected, metric, after }) => ({
        type: 'bar',
        data: {
            labels,
            datasets: [
                {
                    data: values,
                    backgroundColor: values.map((value, index) => (index === selected ? '#00263E' : '#a9bccb')),
                    borderRadius: 4,
                    borderSkipped: 'start',
                    maxBarThickness: 24,
                },
            ],
        },
        options: {
            onClick: (event, elements, chart) => emitClick(chart, elements),
            onHover: (event, elements, chart) => pointerOver(event, elements, chart),
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        title: (items) => titles[items[0].dataIndex],
                        label: (item) => (metric === 'money' ? moneyWhole.format(item.raw) : `${item.raw.toLocaleString('en-CA', { maximumFractionDigits: 2 })} h`),
                        afterLabel: (item) => after[item.dataIndex],
                    },
                },
            },
            scales: {
                x: { grid: { display: false }, ticks: { color: '#5c6873', font: { size: 11 }, autoSkip: true } },
                y: { grid, border: { display: false }, ticks: { maxTicksLimit: 5, callback: (value) => (metric === 'money' ? compactFine(value) : value.toLocaleString('en-CA')) } },
            },
        },
    }),
});

/*
 * Timesheets' add-row line. Search projects (yours first, then by type) or
 * pick office or time off; a project then asks for its phase. The chosen row
 * goes to Livewire's addRow through newRow.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('rowPicker', ({ groups, categories, phases, extras = {} }) => ({
        categories,
        open: false,
        query: '',
        projectQuery: '',
        project: null,
        cursor: 0,
        busy: false,

        init() {
            this.$watch('cursor', () => this.$nextTick(() => this.$refs.list.querySelector('[data-active]')?.scrollIntoView({ block: 'nearest' })));
        },

        get sections() {
            const terms = this.query.trim().toLowerCase().split(/\s+/).filter(Boolean);
            const matches = (text) => terms.every((term) => text.toLowerCase().includes(term));

            // A project's active extras come first: time on them bills as hourly once confirmed.
            if (this.project) {
                return [
                    {
                        label: 'Extras',
                        items: (extras[this.project.job] ?? [])
                            .filter((extra) => matches(extra.name))
                            .map((extra) => ({ key: `extra:${extra.id}`, name: extra.name, meta: 'Hourly extra', extra: extra.id })),
                    },
                    {
                        label: 'Phases',
                        items: phases
                            .filter((phase) => matches(`${phase.code} ${phase.name}`))
                            .map((phase) => ({ key: `phase:${phase.code}`, name: phase.name, meta: phase.code, phase: phase.code })),
                    },
                ].filter((section) => section.items.length);
            }

            const sections = groups
                .filter((group) => terms.length || !group.inactive)
                .map((group) => ({
                    label: group.label,
                    items: group.items
                        .filter((item) => matches(`${item.name} ${item.job} ${item.type}`))
                        .map((item) => ({ ...item, key: `project:${item.job}` })),
                }));

            if (terms.length) {
                sections.push({
                    label: 'Office & time off',
                    items: categories
                        .filter((category) => matches(category.label))
                        .map((category) => ({ key: `category:${category.value}`, name: category.label, meta: '', category: category.value })),
                });
            }

            return sections.filter((section) => section.items.length);
        },

        get options() {
            return this.sections.flatMap((section) => section.items);
        },

        start() {
            this.$refs.search.scrollIntoView({ block: 'center', behavior: 'smooth' });
            this.$refs.search.focus({ preventScroll: true });
            this.open = true;
        },

        move(step) {
            this.open = true;
            this.cursor = Math.min(Math.max(this.cursor + step, 0), this.options.length - 1);
        },

        choose(option) {
            if (option.category) {
                return this.add({ category: option.category });
            }

            if (this.project && option.extra) {
                return this.add({ category: 'project', job_number: this.project.job, phase_code: 'Hours', extra_id: option.extra });
            }

            if (this.project) {
                return this.add({ category: 'project', job_number: this.project.job, phase_code: option.phase });
            }

            this.project = option;
            this.projectQuery = this.query;
            this.query = '';
            this.cursor = 0;
            this.$refs.search.focus();
        },

        back() {
            this.project = null;
            this.query = this.projectQuery;
            this.cursor = 0;
            this.$refs.search.focus();
        },

        close(blur = false) {
            this.open = false;
            this.project = null;
            this.query = '';
            this.cursor = 0;

            if (blur) {
                this.$refs.search.blur();
            }
        },

        async add(row) {
            this.busy = true;
            this.$wire.newRow = { category: 'project', job_number: '', phase_code: '', extra_id: '', ...row };

            try {
                await this.$wire.addRow();
            } finally {
                this.busy = false;
                this.close(true);
            }
        },
    }));
});

/*
 * Leads & PMs: the person menu behind each lead or PM. The lists live on the
 * table (leads, team); the current person is read from data-current, which
 * Livewire keeps up to date after each change.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('assignee', (job, role, list) => ({
        open: false,
        query: '',
        cursor: 0,
        current: '',
        position: '',

        get options() {
            const terms = this.query.trim().toLowerCase().split(/\s+/).filter(Boolean);

            return this[list].filter((person) => terms.every((term) => `${person.label} ${person.name} ${person.hint}`.toLowerCase().includes(term)));
        },

        toggle() {
            if (this.open) {
                return this.close();
            }

            this.current = this.$root.dataset.current;
            this.query = '';
            this.cursor = Math.max(this.options.findIndex((person) => person.name === this.current), 0);
            this.place();
            this.open = true;
            this.$nextTick(() => this.$refs.search.focus({ preventScroll: true }));
        },

        // Fixed to the trigger, so the table's scroll box can't clip it, and kept there on scroll; opens upward near the bottom of the window.
        place() {
            const trigger = this.$refs.trigger.getBoundingClientRect();
            const left = Math.min(trigger.left, window.innerWidth - 272);

            this.position = window.innerHeight - trigger.bottom < 340
                ? `left: ${left}px; bottom: ${window.innerHeight - trigger.top + 4}px`
                : `left: ${left}px; top: ${trigger.bottom + 4}px`;
        },

        close(refocus = false) {
            this.open = false;

            if (refocus) {
                this.$refs.trigger.focus();
            }
        },

        choose(name) {
            this.close(true);

            if (name !== this.current) {
                this.$wire.setRole(job, role, name);
            }
        },
    }));
});
