(function() {
    const { useState, useEffect, Fragment, createElement: h } = wp.element;

    const App = () => {
        const [data, setData] = useState({ terms: [], matches: [] });
        const [filter, setFilter] = useState('all');
        const [page, setPage] = useState(1);
        const perPage = 20;

        useEffect(() => {
            fetch(RebrandTrackerData.ajax_url + '?action=rebrand_tracker_get_data&nonce=' + RebrandTrackerData.nonce)
                .then(res => res.json())
                .then(res => res.success && setData(res.data));
        }, []);

        const filtered = data.matches.filter(m => filter === 'all' || m.context === filter);
        const pages = Math.ceil(filtered.length / perPage);
        const pageItems = filtered.slice((page-1)*perPage, page*perPage);

        const handleExport = () => {
            const url = RebrandTrackerData.ajax_url + '?action=rebrand_tracker_export_csv&nonce=' + RebrandTrackerData.nonce +
                '&filter_term=' + (filter === 'all' ? '' : filter) + '&context=' + filter;
            window.location = url;
        };

        return h(Fragment, null,
            h('h1', null, 'Content Rebrand Tracker'),
            h('div', { className: 'filter-bar' },
                h('label', null, 'Context:'),
                h('select', { value: filter, onChange: e => { setFilter(e.target.value); setPage(1); } },
                    h('option', { value: 'all' }, 'All'),
                    h('option', { value: 'post' }, 'Posts/Pages'),
                    h('option', { value: 'meta' }, 'Meta'),
                    h('option', { value: 'option' }, 'Options')
                ),
                h('button', { onClick: handleExport }, 'Export CSV')
            ),
            h('table', { className: 'table' },
                h('thead', null,
                    h('tr', null,
                        h('th', null, 'Context'),
                        h('th', null, 'ID'),
                        h('th', null, 'Label'),
                        h('th', null, 'Term'),
                        h('th', null, 'Edit')
                    )
                ),
                h('tbody', null,
                    pageItems.map(m =>
                        h('tr', { key: m.context + '-' + m.ID + '-' + m.term },
                            h('td', null, m.context),
                            h('td', null, m.ID),
                            h('td', null, m.label),
                            h('td', null, m.term),
                            h('td', null, h('a', { href: m.edit_url }, 'Edit'))
                        )
                    )
                )
            ),
            h('div', { className: 'pagination' },
                Array.from({ length: pages }, (_, i) =>
                    h('button', {
                        key: i,
                        disabled: i+1 === page,
                        onClick: () => setPage(i+1)
                    }, i+1)
                )
            )
        );
    };

    ReactDOM.render(
        h(App),
        document.getElementById('rebrand-tracker-app')
    );
})();