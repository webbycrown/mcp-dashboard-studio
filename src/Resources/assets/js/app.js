/**
 * MCP Dashboard Studio — Chart Initialization & Interactions v2
 */
document.addEventListener('DOMContentLoaded', function() {
    const mcpCharts = window.mcpCharts || [];

    // Premium color palette
    const palette = {
        backgrounds: [
            'rgba(99, 102, 241, 0.7)',    // Indigo
            'rgba(16, 185, 129, 0.7)',    // Emerald
            'rgba(245, 158, 11, 0.7)',    // Amber
            'rgba(239, 68, 68, 0.7)',     // Red
            'rgba(139, 92, 246, 0.7)',    // Violet
            'rgba(236, 72, 153, 0.7)',    // Pink
            'rgba(6, 182, 212, 0.7)',     // Cyan
            'rgba(34, 197, 94, 0.7)',     // Green
            'rgba(249, 115, 22, 0.7)',    // Orange
            'rgba(168, 85, 247, 0.7)',    // Purple
        ],
        borders: [
            '#818cf8', '#34d399', '#fbbf24', '#f87171',
            '#a78bfa', '#f472b6', '#22d3ee', '#4ade80',
            '#fb923c', '#c084fc',
        ],
        gradients: {
            indigo: ['rgba(99, 102, 241, 0.3)', 'rgba(99, 102, 241, 0.01)'],
            emerald: ['rgba(16, 185, 129, 0.3)', 'rgba(16, 185, 129, 0.01)'],
        }
    };

    // Global Chart.js defaults — read from CSS variables for theme awareness
    function getChartThemeColors() {
        var style = getComputedStyle(document.documentElement);
        return {
            tick: style.getPropertyValue('--chart-tick').trim() || '#94a3b8',
            grid: style.getPropertyValue('--chart-grid').trim() || 'rgba(255,255,255,0.04)',
            border: style.getPropertyValue('--border-color').trim() || 'rgba(255,255,255,0.06)',
        };
    }
    var themeColors = getChartThemeColors();
    if (typeof Chart !== 'undefined') {
        Chart.defaults.color = themeColors.tick;
        Chart.defaults.borderColor = themeColors.border;
        Chart.defaults.font.family = "'Plus Jakarta Sans', 'Inter', sans-serif";
    }

    mcpCharts.forEach(function(chart, chartIndex) {
        const canvasId = chart.id + '_canvas';
        const canvas = document.getElementById(canvasId);
        if (!canvas) return;

        const chartData = chart.data || {};
        const labels = chartData.labels || [];
        const datasets = chartData.datasets || [];

        // Skip charts with no data
        if (labels.length === 0 && datasets.length === 0) return;

        const type = (chart.chartType || 'bar').toLowerCase();

        // Style each dataset
        datasets.forEach(function(ds, i) {
            if (type === 'pie' || type === 'doughnut') {
                ds.backgroundColor = palette.backgrounds;
                ds.borderColor = 'rgba(26, 29, 39, 0.8)';
                ds.borderWidth = 2;
                ds.hoverBorderColor = '#ffffff';
                ds.hoverBorderWidth = 2;
            } else if (type === 'line') {
                ds.borderColor = palette.borders[i % palette.borders.length];
                ds.backgroundColor = createGradient(canvas, palette.gradients.indigo);
                ds.borderWidth = 2.5;
                ds.tension = 0.4;
                ds.fill = true;
                ds.pointRadius = 3;
                ds.pointHoverRadius = 6;
                ds.pointBackgroundColor = palette.borders[i % palette.borders.length];
                ds.pointBorderColor = '#1a1d27';
                ds.pointBorderWidth = 2;
            } else {
                // Bar charts
                ds.backgroundColor = palette.backgrounds[i % palette.backgrounds.length];
                ds.borderColor = palette.borders[i % palette.borders.length];
                ds.borderWidth = 1;
                ds.borderRadius = 6;
                ds.borderSkipped = false;
                ds.maxBarThickness = 48;
                ds.hoverBackgroundColor = palette.borders[i % palette.borders.length];
            }
        });

        // Build chart options
        const isPolar = (type === 'pie' || type === 'doughnut');
        const options = {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false,
            },
            plugins: {
                legend: {
                    display: isPolar || datasets.length > 1,
                    position: isPolar ? 'bottom' : 'top',
                    labels: {
                        color: '#94a3b8',
                        padding: 16,
                        usePointStyle: true,
                        pointStyleWidth: 10,
                        font: {
                            family: "'Plus Jakarta Sans', sans-serif",
                            size: 11,
                        }
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(15, 17, 23, 0.95)',
                    titleColor: '#f1f5f9',
                    bodyColor: '#94a3b8',
                    borderColor: 'rgba(99, 102, 241, 0.3)',
                    borderWidth: 1,
                    cornerRadius: 8,
                    padding: 12,
                    titleFont: {
                        family: "'Outfit', sans-serif",
                        weight: '600',
                        size: 13,
                    },
                    bodyFont: {
                        family: "'Plus Jakarta Sans', sans-serif",
                        size: 12,
                    },
                callbacks: {
                    label: function(context) {
                        var value = context.parsed.y ?? context.parsed ?? context.raw;
                        if (typeof value === 'number') {
                            value = smartFormat(value);
                        }
                        var label = context.dataset.label || context.label || '';
                        return label ? ' ' + label + ': ' + value : ' ' + value;
                    },
                    title: function(tooltipItems) {
                        if (tooltipItems.length > 0) {
                            return tooltipItems[0].label || '';
                        }
                        return '';
                    }
                }
                }
            },
            scales: isPolar ? {} : {
                y: {
                    beginAtZero: true,
                    grid: {
                        color: 'rgba(255, 255, 255, 0.04)',
                        drawBorder: false,
                    },
                    border: {
                        display: false,
                    },
                    ticks: {
                        color: '#64748b',
                        font: { size: 11 },
                        padding: 8,
                        callback: function(value) {
                            if (value >= 1000000) return (value / 1000000).toFixed(1) + 'M';
                            if (value >= 1000) return (value / 1000).toFixed(0) + 'K';
                            return value;
                        }
                    }
                },
                x: {
                    grid: {
                        display: false,
                        drawBorder: false,
                    },
                    border: {
                        display: false,
                    },
                    ticks: {
                        color: '#64748b',
                        font: { size: 11 },
                        padding: 8,
                        maxRotation: 45,
                        autoSkip: true,
                        maxTicksLimit: 12,
                    }
                }
            }
        };
        // Special options for pie/doughnut
        if (type === 'doughnut') {
            options.cutout = '55%';
        }
        new Chart(canvas, {
            type: type,
            data: { labels: labels, datasets: datasets },
            options: options
        });
    });

    /**
     * Create a vertical gradient fill for line chart area.
     */
    function createGradient(canvas, colors) {
        try {
            const ctx = canvas.getContext('2d');
            const gradient = ctx.createLinearGradient(0, 0, 0, canvas.parentElement.clientHeight || 280);
            gradient.addColorStop(0, colors[0]);
            gradient.addColorStop(1, colors[1]);
            return gradient;
        } catch (e) {
            return colors[0];
        }
    }

    /**
     * Smart number formatting — abbreviates large numbers (K, M, B)
     * and formats currency-like values with appropriate precision.
     */
    function smartFormat(num) {
        if (num === null || num === undefined || isNaN(num)) return '0';
        var abs = Math.abs(num);
        if (abs >= 1e9)  return (num / 1e9).toFixed(2) + 'B';
        if (abs >= 1e6)  return (num / 1e6).toFixed(2) + 'M';
        if (abs >= 1e4)  return (num / 1e3).toFixed(1) + 'K';
        if (abs >= 1000) return num.toLocaleString();
        if (num === Math.floor(num)) return num.toLocaleString();
        return num.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    /**
     * Number formatting for KPI values — auto-format and abbreviate large numbers.
     */
    document.querySelectorAll('.kpi-value').forEach(function(el) {
        var raw = el.textContent.trim().replace(/,/g, '').replace(/^\$/, '');
        var num = parseFloat(raw);
        if (!isNaN(num)) {
            var prefix = el.textContent.trim().startsWith('$') ? '$' : '';
            el.textContent = prefix + smartFormat(num);
            // Add a title attribute with the full value for accessibility
            el.setAttribute('title', prefix + num.toLocaleString());
        }
    });

    // =========================================================================
    //  AJAX Filter System — Real-time dashboard filtering
    // =========================================================================

    const slug = window.mcpDashboardSlug || '';
    const filterBar = document.getElementById('dashboard-filters');
    const csrfToken = document.querySelector('meta[name="csrf-token"]');

    if (slug && filterBar) {
        var filterSelects = filterBar.querySelectorAll('.mcp-filter-select');
        var filterInputs  = filterBar.querySelectorAll('.mcp-filter-input');
        var filterTimeout = null;

        function bindFilterChange(element) {
            element.addEventListener('change', function() {
                clearTimeout(filterTimeout);
                filterTimeout = setTimeout(applyFilters, 300);
            });
        }

        filterSelects.forEach(bindFilterChange);
        filterInputs.forEach(bindFilterChange);

        function applyFilters() {
            // Collect all active filter values from selects and inputs
            var filters = {};
            filterSelects.forEach(function(select) {
                var column = select.dataset.filterColumn;
                var value = select.value;
                if (column && value) {
                    filters[column] = value;
                }
            });
            filterInputs.forEach(function(input) {
                var column = input.dataset.filterColumn;
                var type   = input.dataset.filterType || 'value';
                var value  = input.value;
                if (column && value) {
                    // For date ranges, use suffixed keys (e.g. created_at_from, created_at_to)
                    var key = type === 'date_from' ? column + '_from'
                            : type === 'date_to'   ? column + '_to'
                            : column;
                    filters[key] = value;
                }
            });

            // Show loading state
            filterBar.classList.add('is-loading');
            document.querySelectorAll('.dashboard-card').forEach(function(card) {
                card.style.opacity = '0.5';
                card.style.transition = 'opacity 0.2s ease';
            });

            // Send AJAX request
            fetch(window.mcpDashboardFilterUrl || ('/dashboard-studio/' + slug + '/filter'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken ? csrfToken.content : '',
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ filters: filters }),
            })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data.success && data.components) {
                    updateDashboard(data.components);
                }
            })
            .catch(function(err) {
                console.error('Filter request failed:', err);
            })
            .finally(function() {
                filterBar.classList.remove('is-loading');
                document.querySelectorAll('.dashboard-card').forEach(function(card) {
                    card.style.opacity = '1';
                });
            });
        }

        /**
         * Update dashboard components with filtered data from server.
         */
        function updateDashboard(components) {
            components.forEach(function(comp) {
                const type = comp.type || '';
                const id = comp.id || '';

                if (type === 'kpi') {
                    updateKpi(comp);
                } else if (type.includes('chart')) {
                    updateChart(comp);
                } else if (type === 'table') {
                    updateTable(comp);
                }
            });
        }

        /**
         * Update a KPI card value.
         */
        function updateKpi(comp) {
            const wrapper = document.querySelector('[class*="kpi-card"]');
            // Find the KPI by matching title text
            const title = comp.data?.title || '';
            document.querySelectorAll('.kpi-card').forEach(function(card) {
                const cardTitle = card.querySelector('.kpi-title');
                if (cardTitle && cardTitle.textContent.trim().toUpperCase() === title.toUpperCase()) {
                    const valueEl = card.querySelector('.kpi-value');
                    if (valueEl) {
                        const val = comp.data?.value ?? 0;
                        const num = parseFloat(val);
                        valueEl.textContent = !isNaN(num) ? num.toLocaleString() : val;

                        // Flash animation
                        valueEl.style.color = '#818cf8';
                        setTimeout(function() { valueEl.style.color = ''; }, 600);
                    }
                }
            });
        }

        /**
         * Update a chart with new filtered data.
         */
        function updateChart(comp) {
            const canvasId = comp.id + '_canvas';
            const canvas = document.getElementById(canvasId);
            if (!canvas) return;

            const chartData = comp.data?.data || {};
            const labels = chartData.labels || [];
            const datasets = chartData.datasets || [];
            if (labels.length === 0) return;

            // Destroy existing chart instance if it exists
            const existingChart = Chart.getChart(canvas);
            if (existingChart) {
                existingChart.data.labels = labels;
                existingChart.data.datasets.forEach(function(ds, i) {
                    if (datasets[i]) {
                        ds.data = datasets[i].data || [];
                    }
                });
                existingChart.update('active');
            }
        }

        /**
         * Update a data table with filtered rows.
         */
        function updateTable(comp) {
            const title = comp.data?.title || '';

            document.querySelectorAll('.table-card-wrapper').forEach(function(wrapper) {
                const titleEl = wrapper.querySelector('.table-title span');
                if (!titleEl || titleEl.textContent.trim() !== title) return;

                const tbody = wrapper.querySelector('.data-table tbody');
                const columns = comp.data?.columns || comp.data?.headers || [];
                const rows = comp.data?.rows || [];

                if (!tbody || columns.length === 0) return;

                // Update record count badge
                const badge = wrapper.querySelector('.table-badge');
                if (badge) {
                    badge.textContent = rows.length + ' ' + (rows.length === 1 ? 'record' : 'records');
                }

                // Rebuild tbody
                if (rows.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="' + Math.max(columns.length, 1) + '" class="table-empty">No records found.</td></tr>';
                    return;
                }

                let html = '';
                rows.forEach(function(row) {
                    html += '<tr>';
                    columns.forEach(function(col) {
                        const key = typeof col === 'object' ? (col.name || '') : col;
                        let val = row[key] ?? '';

                        // Format numbers
                        if (typeof val === 'number' || (typeof val === 'string' && !isNaN(val) && val !== '')) {
                            const num = parseFloat(val);
                            if (!isNaN(num)) {
                                val = num === Math.floor(num) ? num.toLocaleString() : num.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                            }
                        }

                        // Status pills
                        const colLower = key.toLowerCase();
                        if (colLower.includes('status') || colLower.includes('active')) {
                            const clean = String(val).replace(/[_-]/g, '').toLowerCase();
                            let cls = '';
                            if (['instock','active','completed','1','yes'].includes(clean)) cls = 'sp-success';
                            else if (['outofstock','inactive','cancelled','0','no'].includes(clean)) cls = 'sp-danger';
                            else if (['pending','processing'].includes(clean)) cls = 'sp-warning';

                            if (cls) {
                                val = '<span class="status-pill ' + cls + '">' + String(val).replace(/_/g, ' ') + '</span>';
                                html += '<td>' + val + '</td>';
                                return;
                            }
                        }

                        // Truncate long text
                        if (typeof val === 'string' && val.length > 60) {
                            val = val.substring(0, 57) + '...';
                        }

                        html += '<td>' + (val === null ? '' : val) + '</td>';
                    });
                    html += '</tr>';
                });

                tbody.innerHTML = html;
            });
        }
    }

    // =========================================================================
    //  Data Table Pagination (Vanilla JS)
    // =========================================================================
    var perPage = window.mcpTablePagination || 5;

    document.querySelectorAll('.table-card-wrapper').forEach(function(wrapper) {
        var table = wrapper.querySelector('.data-table');
        var tbody = table ? table.querySelector('tbody') : null;
        if (!tbody) return;

        var rows = Array.from(tbody.querySelectorAll('tr'));
        // Don't paginate if empty state
        if (rows.length === 0 || rows[0].querySelector('.table-empty')) return;

        if (rows.length > perPage) {
            var totalPages = Math.ceil(rows.length / perPage);
            var currentPage = 1;

            var paginationContainer = document.createElement('div');
            paginationContainer.className = 'mcp-pagination-controls';
            paginationContainer.style.padding = '1rem 1.5rem';
            paginationContainer.style.borderTop = '1px solid var(--border-color)';
            paginationContainer.style.display = 'flex';
            paginationContainer.style.justifyContent = 'space-between';
            paginationContainer.style.alignItems = 'center';
            paginationContainer.style.fontSize = '0.8rem';
            paginationContainer.style.color = 'var(--text-muted)';
            paginationContainer.style.flexWrap = 'wrap';
            paginationContainer.style.gap = '1rem';

            var infoSpan = document.createElement('span');
            var btnContainer = document.createElement('div');
            btnContainer.style.display = 'flex';
            btnContainer.style.gap = '0.25rem';

            paginationContainer.appendChild(infoSpan);
            paginationContainer.appendChild(btnContainer);
            wrapper.appendChild(paginationContainer);

            function renderPage(page) {
                currentPage = page;
                var start = (page - 1) * perPage;
                var end = start + perPage;

                rows.forEach(function(row, index) {
                    row.style.display = (index >= start && index < end) ? '' : 'none';
                });

                infoSpan.textContent = 'Showing ' + (start + 1) + '–' + Math.min(end, rows.length) + ' of ' + rows.length;
                renderButtons();
            }

            function renderButtons() {
                btnContainer.innerHTML = '';

                function createBtn(text, targetPage, disabled, active) {
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.innerHTML = text;
                    btn.style.padding = '0.3rem 0.65rem';
                    btn.style.borderRadius = '6px';
                    btn.style.fontSize = '0.8rem';
                    btn.style.cursor = disabled ? 'default' : 'pointer';
                    btn.style.border = '1px solid ' + (active ? 'var(--primary)' : 'var(--border-color)');
                    btn.style.background = active ? 'var(--primary)' : 'transparent';
                    btn.style.color = active ? '#fff' : 'var(--text-secondary)';
                    if (disabled) btn.style.opacity = '0.35';

                    if (!disabled && !active) {
                        btn.onclick = function() { renderPage(targetPage); };
                        btn.onmouseover = function() { btn.style.background = 'var(--bg-elevated)'; };
                        btn.onmouseout = function() { btn.style.background = 'transparent'; };
                    }
                    return btn;
                }

                btnContainer.appendChild(createBtn('‹', currentPage - 1, currentPage === 1, false));

                var startPage = Math.max(1, currentPage - 2);
                var endPage = Math.min(totalPages, currentPage + 2);

                for (var i = startPage; i <= endPage; i++) {
                    btnContainer.appendChild(createBtn(i, i, false, i === currentPage));
                }

                btnContainer.appendChild(createBtn('›', currentPage + 1, currentPage === totalPages, false));
            }

            renderPage(1);
        }
    });

    // =========================================================================
    //  Theme Toggle — Dark / Light mode switching
    // =========================================================================

    var toggleBtn = document.getElementById('theme-toggle');
    if (toggleBtn) {
        // Set initial icon based on current theme
        var currentTheme = document.documentElement.getAttribute('data-theme') || 'dark';
        toggleBtn.textContent = currentTheme === 'dark' ? '🌙' : '☀️';

        toggleBtn.addEventListener('click', function() {
            var html = document.documentElement;
            var current = html.getAttribute('data-theme') || 'dark';
            var next = current === 'dark' ? 'light' : 'dark';

            // Apply theme
            html.setAttribute('data-theme', next);
            localStorage.setItem('dashboard-studio-theme', next);

            // Update icon
            toggleBtn.textContent = next === 'dark' ? '🌙' : '☀️';

            // Update meta theme-color
            var metaTheme = document.querySelector('meta[name="theme-color"]');
            if (metaTheme) {
                metaTheme.content = next === 'dark' ? '#0f1117' : '#f0f4ff';
            }

            // Re-color all Chart.js instances for the new theme
            var newColors = getChartThemeColors();
            // Small delay to let CSS variables update
            setTimeout(function() {
                var updatedColors = getChartThemeColors();
                if (typeof Chart !== 'undefined') {
                    Chart.helpers.each(Chart.instances, function(chart) {
                        if (chart.options.scales && chart.options.scales.y) {
                            chart.options.scales.y.grid.color = updatedColors.grid;
                            chart.options.scales.y.ticks.color = updatedColors.tick;
                        }
                        if (chart.options.scales && chart.options.scales.x) {
                            chart.options.scales.x.ticks.color = updatedColors.tick;
                        }
                        if (chart.options.plugins && chart.options.plugins.legend) {
                            chart.options.plugins.legend.labels.color = updatedColors.tick;
                        }
                        chart.update('none');
                    });
                }
            }, 50);
        });
    }

});
