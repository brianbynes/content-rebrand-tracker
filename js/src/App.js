import { useState, useEffect, Fragment } from '@wordpress/element';
import FilterBar from './components/FilterBar.js';
import DataTable from './components/DataTable.js';
import Pagination from './components/Pagination.js';

const App = () => {
    const [data, setData] = useState({ terms: [], matches: [] });
    const [filter, setFilter] = useState('all');
    const [page, setPage] = useState(1);
    const [searchTerm, setSearchTerm] = useState('');
    const [replaceTerm, setReplaceTerm] = useState('');
    const [replacedItems, setReplacedItems] = useState(new Set());
    const perPage = 20;

    const fetchData = () => {
        return fetch(`${RebrandTrackerData.ajax_url}?action=rebrand_tracker_get_data&nonce=${RebrandTrackerData.nonce}`)
            .then(res => res.json())
            .then(res => {
                if (res.success) {
                    setData(res.data);
                    // Pre-fill search input with saved term if available
                    if (res.data.terms && res.data.terms.length > 0) {
                        setSearchTerm(res.data.terms[0]);
                    }
                }
            });
    };

    useEffect(fetchData, []);
    // Load persisted replace term from localStorage
    useEffect(() => {
        const saved = localStorage.getItem('rebrand_replaceTerm');
        if (saved) setReplaceTerm(saved);
    }, []);
    // Persist replace term to localStorage on change
    useEffect(() => {
        localStorage.setItem('rebrand_replaceTerm', replaceTerm);
    }, [replaceTerm]);

    // Load persisted replaced items from localStorage
    useEffect(() => {
        const saved = localStorage.getItem('rebrand_replacedItems');
        if (saved) setReplacedItems(new Set(JSON.parse(saved)));
    }, []);
    // Persist replaced items to localStorage on change
    useEffect(() => {
        localStorage.setItem('rebrand_replacedItems', JSON.stringify(Array.from(replacedItems)));
    }, [replacedItems]);

    const handleSearch = () => {
        if (!searchTerm.trim()) return;
        const newTerms = [searchTerm.trim()];
        fetch(RebrandTrackerData.ajax_url, {
            method: 'POST',
            body: new URLSearchParams({
                action: 'rebrand_tracker_set_terms',
                nonce: RebrandTrackerData.nonce,
                terms: JSON.stringify(newTerms),
            }),
        })
            .then(res => res.json())
            .then(res => {
                if (res.success) {
                    setPage(1);
                    fetchData();
                }
            });
    };

    // Handler to clear all persisted state
    const handleClearMemory = () => {
        // Remove from localStorage
        localStorage.removeItem('rebrand_replaceTerm');
        localStorage.removeItem('rebrand_replacedItems');
        // Reset state
        setReplaceTerm('');
        setReplacedItems(new Set());
    };

    // Handler to replace individual items
    const handleReplaceItem = (item) => {
        if (!replaceTerm.trim()) {
            alert('Please enter a Replace term.');
            return;
        }
        fetch(RebrandTrackerData.ajax_url, {
            method: 'POST',
            body: new URLSearchParams({
                action: 'rebrand_tracker_replace_item',
                nonce: RebrandTrackerData.nonce,
                context: item.context,
                id: item.ID,
                term: item.term,
                replace: replaceTerm.trim(),
            }),
        })
        .then(res => res.json())
        .then(res => {
            if (res.success) {
                // keep the replaceTerm so it stays in the input
                // setReplaceTerm('');
                // Mark item as replaced instead of removing it
                setReplacedItems(prev => {
                    const updated = new Set(prev);
                    updated.add(`${item.context}-${item.ID}-${item.term}`);
                    return updated;
                });
            } else {
                alert(res.data);
            }
        });
    };

    const filtered = data.matches.filter(m => filter === 'all' || m.context === filter);
    const pages = Math.ceil(filtered.length / perPage);
    const pageItems = filtered.slice((page-1)*perPage, page*perPage);

    return (
        <Fragment>
            <div className="search-replace">
                <input
                    type="text"
                    placeholder="Search term"
                    value={searchTerm}
                    onChange={e => setSearchTerm(e.target.value)}
                />
                <input
                    type="text"
                    placeholder="Replace term"
                    value={replaceTerm}
                    onChange={e => setReplaceTerm(e.target.value)}
                />
                <button onClick={handleSearch}>Search</button>
                <button type="button" onClick={handleClearMemory}>Clear All Memory</button>
            </div>
            <h1>Content Rebrand Tracker</h1>
            <FilterBar filter={filter} onFilterChange={value => { setFilter(value); setPage(1); }} />
            <DataTable items={pageItems} onReplace={handleReplaceItem} replacedItems={replacedItems} replaceTerm={replaceTerm} />
            <Pagination page={page} pages={pages} onPageChange={setPage} />
        </Fragment>
    );
};

export default App;