const DataTable = ({ items, onReplace, replacedItems }) => (
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
            {items.map(m => {
                const keyStr = `${m.context}-${m.ID}-${m.term}`;
                const isReplaced = replacedItems.has(keyStr);
                return (
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
                            {isReplaced ? (
                                <span style={{
                                    display: 'inline-block',
                                    background: '#d4edda',
                                    color: '#155724',
                                    padding: '4px 8px',
                                    borderRadius: '4px',
                                    fontSize: '0.9em'
                                }}>Replaced</span>
                            ) : (
                                <button type="button" onClick={() => onReplace(m)}>Replace</button>
                            )}
                        </td>
                    </tr>
                );
            })}
        </tbody>
    </table>
);

export default DataTable;