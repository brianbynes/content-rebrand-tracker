import { Fragment } from '@wordpress/element';

const FilterBar = ({ filter, onFilterChange, onExport }) => (
    <div className="filter-bar">
        <label>Context:</label>
        <select value={filter} onChange={e => onFilterChange(e.target.value)}>
            <option value="all">All</option>
            <option value="post">Posts/Pages</option>
            <option value="meta">Meta</option>
            <option value="option">Options</option>
        </select>
        <button onClick={onExport}>Export CSV</button>
    </div>
);

export default FilterBar;