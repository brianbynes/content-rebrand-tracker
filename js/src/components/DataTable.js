const DataTable = ({ items, onReplace }) => (
    <table className="table">
        <thead>
            <tr>
                <th>Context</th>
                <th>ID</th>
                <th>Label</th>
                <th>Term</th>
                <th>View</th>
                <th>Replace</th>
            </tr>
        </thead>
        <tbody>
            {items.map(m => (
                <tr key={`${m.context}-${m.ID}-${m.term}`}>
                    <td>{m.context}</td>
                    <td>{m.ID}</td>
                    <td>{m.label}</td>
                    <td>{m.term}</td>
                    <td>
                        <a
                            href={`${m.view_url}${m.view_url.includes('?') ? '&' : '?'}rebrand_term=${encodeURIComponent(m.term)}`}
                            target="_blank"
                            rel="noopener noreferrer"
                        >View</a>
                    </td>
                    <td>
                        <button type="button" onClick={() => onReplace(m)}>Replace</button>
                    </td>
                </tr>
            ))}
        </tbody>
    </table>
);

export default DataTable;