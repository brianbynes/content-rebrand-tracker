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
        // Show visual feedback that the operation is in progress
        const button = document.querySelector('button[type="button"]');
        const originalText = button.textContent;
        button.textContent = 'Clearing...';
        button.disabled = true;
        
        // Remove from localStorage
        localStorage.removeItem('rebrand_replaceTerm');
        localStorage.removeItem('rebrand_replacedItems');
        // Reset state
        setReplaceTerm('');
        setReplacedItems(new Set());

        // Send request to backend to clear plugin-specific data
        fetch(RebrandTrackerData.ajax_url, {
            method: 'POST',
            body: new URLSearchParams({
                action: 'clear_plugin_memory',
                nonce: RebrandTrackerData.nonce
            })
        })
        .then(res => res.json())
        .then(res => {
            if (res.success) {
                // Success notification with enhanced visual feedback
                const notification = document.createElement('div');
                notification.innerHTML = '<span style="font-size:18px">✓</span> Memory cleared successfully!';
                notification.style.cssText = 'position:fixed; top:50px; left:50%; transform:translateX(-50%) scale(0.9); opacity:0; background:#4CAF50; color:white; padding:12px 24px; border-radius:4px; z-index:10000; box-shadow:0 3px 12px rgba(0,0,0,0.3); font-weight:bold; font-size:15px; display:flex; align-items:center; gap:8px; border-left:5px solid #2E7D32;';
                document.body.appendChild(notification);
                
                // Animate in
                setTimeout(() => {
                    notification.style.transition = 'transform 0.3s ease, opacity 0.3s ease';
                    notification.style.transform = 'translateX(-50%) scale(1)';
                    notification.style.opacity = '1';
                }, 10);
                
                // Also flash the table briefly to indicate data refresh
                const dataTable = document.querySelector('.table');
                if (dataTable) {
                    dataTable.style.transition = 'background-color 0.5s ease';
                    dataTable.style.backgroundColor = '#e8f5e9';
                    setTimeout(() => {
                        dataTable.style.backgroundColor = '';
                    }, 1000);
                }
                
                // Remove notification after 3.5 seconds
                setTimeout(() => {
                    notification.style.transform = 'translateX(-50%) scale(0.9)';
                    notification.style.opacity = '0';
                    setTimeout(() => notification.remove(), 300);
                }, 3500);
                
                // Refresh data
                fetchData();
            } else {
                alert('Failed to clear memory: ' + (res.data || 'Unknown error'));
            }
            
            // Reset button state
            const button = document.querySelector('button[type="button"]');
            button.textContent = 'Clear All Memory';
            button.disabled = false;
        })
        .catch(error => {
            console.error('Error clearing memory:', error);
            alert('Error clearing memory. Please check console.');
            
            // Reset button on error
            const button = document.querySelector('button[type="button"]');
            button.textContent = 'Clear All Memory';
            button.disabled = false;
        });
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