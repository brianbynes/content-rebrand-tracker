import { Fragment } from '@wordpress/element';

const FilterBar = ({ filter, onFilterChange }) => (
    <div className="filter-bar">
        <label>Context:</label>
        <select value={filter} onChange={e => onFilterChange(e.target.value)}>
            <option value="all">All</option>
            <option value="post">Posts/Pages</option>
            <option value="meta">Meta</option>
            <option value="option">Options</option>
        </select>
    </div>
);

export default FilterBar;