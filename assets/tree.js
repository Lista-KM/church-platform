fetch('/api/fetch_tree.php')
    .then(res => res.json())
    .then(data => {
        const svg = d3.select("#tree").append("svg")
            .attr("width", 800)
            .attr("height", 500);

        const root = d3.hierarchy(data);
        const treeLayout = d3.tree().size([800, 400]);
        treeLayout(root);

        svg.selectAll('line')
            .data(root.links())
            .join('line')
            .attr('x1', d => d.source.x)
            .attr('y1', d => d.source.y)
            .attr('x2', d => d.target.x)
            .attr('y2', d => d.target.y)
            .attr('stroke', 'black');

        svg.selectAll('circle')
            .data(root.descendants())
            .join('circle')
            .attr('cx', d => d.x)
            .attr('cy', d => d.y)
            .attr('r', 20)
            .attr('fill', 'lightblue');

        svg.selectAll('text')
            .data(root.descendants())
            .join('text')
            .attr('x', d => d.x)
            .attr('y', d => d.y - 30)
            .attr('text-anchor', 'middle')
            .text(d => d.data.name);
    });