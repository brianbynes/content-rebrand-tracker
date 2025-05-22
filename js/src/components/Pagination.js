const Pagination = ({ page, pages, onPageChange }) => (
    <div className="pagination">
        {Array.from({ length: pages }, (_, i) => (
            <button
                key={i}
                disabled={i + 1 === page}
                onClick={() => onPageChange(i + 1)}
            >
                {i + 1}
            </button>
        ))}
    </div>
);

export default Pagination;