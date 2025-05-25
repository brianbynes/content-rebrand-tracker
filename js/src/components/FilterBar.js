import { memo } from '@wordpress/element';

const FilterBar = ({ filter, onFilterChange }) => (
    <div className="filter-bar">
        <label htmlFor="context-filter">Filter by context:</label>
        <select id="context-filter" value={filter} onChange={e => onFilterChange(e.target.value)}>
            <option value="all">All</option>
            <option value="post">Posts</option>
            <option value="meta">Meta</option>
            <option value="yoast">Yoast</option> {/* Added Yoast option */}
            <option value="option">Options</option>
        </select>
    </div>
);

export default memo(FilterBar);