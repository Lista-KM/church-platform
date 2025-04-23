<!DOCTYPE html>
<html>

<head>
    <style>
    body {
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
        margin: 0;
        padding: 0;
    }

    .referral-tree-container {
        width: 100%;
        height: 600px;
        position: relative;
        overflow: hidden;
    }

    .node {
        cursor: pointer;
    }

    .node-rect {
        fill: white;
        stroke: #e5e7eb;
        stroke-width: 1px;
        rx: 8px;
        ry: 8px;
        filter: drop-shadow(0 1px 2px rgba(0, 0, 0, 0.05));
        transition: all 0.3s;
    }

    .node-rect.root {
        fill: #eef2ff;
        stroke: #c7d2fe;
    }

    .node-rect:hover {
        stroke: #6366f1;
        stroke-width: 2px;
    }

    .avatar {
        fill: #6366f1;
        transition: all 0.3s;
    }

    .avatar.root {
        fill: #4f46e5;
    }

    .avatar-text {
        fill: white;
        font-weight: 500;
        text-anchor: middle;
        dominant-baseline: central;
        user-select: none;
    }

    .name-text {
        fill: #1f2937;
        font-weight: 500;
        dominant-baseline: central;
    }

    .count-text {
        fill: #6b7280;
        font-size: 0.85em;
        dominant-baseline: central;
    }

    .register-text {
        fill: #4f46e5;
        font-size: 0.75em;
        text-anchor: middle;
        cursor: pointer;
    }

    .link {
        fill: none;
        stroke: #d1d5db;
        stroke-width: 1.5px;
    }

    .link.highlighted {
        stroke: #6366f1;
        stroke-width: 2px;
    }

    .controls {
        position: absolute;
        bottom: 20px;
        right: 20px;
        display: flex;
        gap: 8px;
    }

    .control-btn {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: white;
        border: 1px solid #e5e7eb;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        font-size: 18px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    }

    .control-btn:hover {
        background: #f9fafb;
    }

    .tooltip {
        position: absolute;
        padding: 8px 12px;
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 6px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        pointer-events: none;
        opacity: 0;
        transition: opacity 0.2s;
        font-size: 14px;
    }
    </style>
</head>

<body>
    <div class="referral-tree-container">
        <svg id="referral-tree"></svg>
        <div class="controls">
            <div class="control-btn" id="zoom-in">+</div>
            <div class="control-btn" id="zoom-out">-</div>
            <div class="control-btn" id="zoom-reset">⟲</div>
        </div>
        <div class="tooltip" id="tooltip"></div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/d3/7.8.5/d3.min.js"></script>
    <script>
    // This function would be called with data from PHP
    function initReferralTree(treeData) {
        const container = document.querySelector('.referral-tree-container');
        const width = container.offsetWidth;
        const height = container.offsetHeight;

        // Create the SVG
        const svg = d3.select('#referral-tree')
            .attr('width', width)
            .attr('height', height);

        // Create a group that we'll apply transforms to
        const g = svg.append('g');

        // Create zoom behavior
        const zoom = d3.zoom()
            .scaleExtent([0.1, 3])
            .on('zoom', (event) => {
                g.attr('transform', event.transform);
            });

        // Apply zoom behavior to SVG
        svg.call(zoom);

        // Center the tree initially
        const initialTransform = d3.zoomIdentity
            .translate(width / 2, 80)
            .scale(1);

        svg.call(zoom.transform, initialTransform);

        // Create tree layout
        const treeLayout = d3.tree()
            .size([width - 200, height - 160]);

        // Create hierarchy from data
        const root = d3.hierarchy(treeData);

        // Assign x,y positions to nodes
        treeLayout(root);

        // Create links
        const links = g.selectAll('.link')
            .data(root.links())
            .enter()
            .append('path')
            .attr('class', 'link')
            .attr('d', d => {
                return `M${d.source.x},${d.source.y} 
                  C${d.source.x},${(d.source.y + d.target.y) / 2} 
                  ${d.target.x},${(d.source.y + d.target.y) / 2} 
                  ${d.target.x},${d.target.y}`;
            });

        // Create nodes
        const nodes = g.selectAll('.node')
            .data(root.descendants())
            .enter()
            .append('g')
            .attr('class', 'node')
            .attr('transform', d => `translate(${d.x},${d.y})`)
            .on('mouseover', function(event, d) {
                // Highlight node and connections
                d3.select(this).select('.node-rect')
                    .style('stroke', '#6366f1')
                    .style('stroke-width', '2px');

                // Show tooltip
                const tooltip = d3.select('#tooltip');
                tooltip.style('opacity', 1)
                    .html(`<strong>${d.data.name}</strong><br>${d.data.referralCount} referrals`)
                    .style('left', (event.pageX + 10) + 'px')
                    .style('top', (event.pageY - 20) + 'px');

                // Highlight connected links
                links.classed('highlighted', l =>
                    l.source === d || l.target === d);
            })
            .on('mouseout', function() {
                // Remove highlight
                d3.select(this).select('.node-rect')
                    .style('stroke', d => d.depth === 0 ? '#c7d2fe' : '#e5e7eb')
                    .style('stroke-width', '1px');

                // Hide tooltip
                d3.select('#tooltip').style('opacity', 0);

                // Remove link highlight
                links.classed('highlighted', false);
            });

        // Add node backgrounds
        nodes.append('rect')
            .attr('class', d => `node-rect ${d.depth === 0 ? 'root' : ''}`)
            .attr('x', -70)
            .attr('y', -20)
            .attr('width', 140)
            .attr('height', 45);

        // Add avatars
        nodes.append('circle')
            .attr('class', d => `avatar ${d.depth === 0 ? 'root' : ''}`)
            .attr('cx', -50)
            .attr('cy', 0)
            .attr('r', 15);

        // Add avatar text (first letter of name)
        nodes.append('text')
            .attr('class', 'avatar-text')
            .attr('x', -50)
            .attr('y', 0)
            .text(d => d.data.name.substring(0, 1));

        // Add names
        nodes.append('text')
            .attr('class', 'name-text')
            .attr('x', -25)
            .attr('y', 0)
            .text(d => d.data.name);

        // Add referral counts
        nodes.append('text')
            .attr('class', 'count-text')
            .attr('x', 25)
            .attr('y', 0)
            .text(d => `(${d.data.referralCount} referrals)`);

        // Add register text for nodes with children
        nodes.filter(d => d.depth > 0 && d.data.referralCount > 0)
            .append('text')
            .attr('class', 'register-text')
            .attr('x', 0)
            .attr('y', 35)
            .text('Register to see complete tree');

        // Setup control buttons
        document.getElementById('zoom-in').addEventListener('click', () => {
            svg.transition()
                .duration(500)
                .call(zoom.scaleBy, 1.2);
        });

        document.getElementById('zoom-out').addEventListener('click', () => {
            svg.transition()
                .duration(500)
                .call(zoom.scaleBy, 0.8);
        });

        document.getElementById('zoom-reset').addEventListener('click', () => {
            svg.transition()
                .duration(500)
                .call(zoom.transform, initialTransform);
        });

        // Handle window resize
        window.addEventListener('resize', () => {
            const newWidth = container.offsetWidth;
            const newHeight = container.offsetHeight;

            svg.attr('width', newWidth)
                .attr('height', newHeight);

            treeLayout.size([newWidth - 200, newHeight - 160]);
            treeLayout(root);

            // Update links
            links.attr('d', d => {
                return `M${d.source.x},${d.source.y} 
                  C${d.source.x},${(d.source.y + d.target.y) / 2} 
                  ${d.target.x},${(d.source.y + d.target.y) / 2} 
                  ${d.target.x},${d.target.y}`;
            });

            // Update nodes
            nodes.attr('transform', d => `translate(${d.x},${d.y})`);
        });
    }

    // PHP would generate this data and inject it
    // This is just a sample data structure - in reality PHP would generate this
    const sampleTreeData = {
        name: "Jane Doe",
        id: 1,
        referralCount: 3,
        children: [{
                name: "John Smith",
                id: 2,
                referralCount: 2,
                children: []
            },
            {
                name: "Robert Johnson",
                id: 3,
                referralCount: 0,
                children: []
            },
            {
                name: "Sarah Williams",
                id: 4,
                referralCount: 5,
                children: []
            }
        ]
    };

    // Initialize the tree with sample data
    initReferralTree(sampleTreeData);
    </script>
</body>

</html>