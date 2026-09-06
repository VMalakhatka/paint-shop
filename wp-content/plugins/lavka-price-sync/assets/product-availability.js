(function () {
    'use strict';
    window.LPS_AVAILABILITY = function (root, groups, warehouses) {
        const panel = root.querySelector('[data-availability-editor]');
        const field = (key) => panel.querySelector('[data-av="' + key + '"]');
        const ranges = ['availabilityPercentFrom', 'availabilityPercentTo', 'stockoutPercentFrom', 'stockoutPercentTo'];
        function refresh() {
            const selected = field('context').value;
            const options = [field('context').options[0].cloneNode(true)];
            const ids = Array.from(warehouses.selectedOptions).map((option) => Number(option.value));
            Array.from(warehouses.selectedOptions).forEach((option) => options.push(new Option(option.textContent, 'warehouse:' + option.value)));
            groups.filter((group) => group.warehouseIds.length && group.warehouseIds.every((id) => ids.includes(Number(id))))
                .forEach((group) => options.push(new Option(group.name + ' [' + group.warehouseIds.join(', ') + ']', 'group:' + group.code)));
            if (selected && !options.some((option) => option.value === selected)) options.push(new Option(selected, selected));
            field('context').replaceChildren(...options);
            field('context').value = selected;
        }
        function read() {
            const result = { enabled: field('enabled').checked };
            const context = field('context').value;
            if (context.startsWith('warehouse:')) result.warehouseId = Number(context.slice(10));
            if (context.startsWith('group:')) result.groupCode = context.slice(6);
            const filter = {};
            ranges.forEach((key) => { if (field(key).value !== '') filter[key] = Number(field(key).value); });
            const statuses = Array.from(field('availabilityStatus').selectedOptions).map((option) => option.value);
            if (statuses.length) filter.availabilityStatus = statuses;
            if (Object.keys(filter).length) result.filter = filter;
            return result;
        }
        function apply(value) {
            value = value || {};
            field('enabled').checked = value.enabled === true;
            const context = value.groupCode ? 'group:' + value.groupCode : value.warehouseId ? 'warehouse:' + value.warehouseId : '';
            refresh();
            if (context && !Array.from(field('context').options).some((option) => option.value === context)) field('context').add(new Option(context, context));
            field('context').value = context;
            ranges.forEach((key) => { field(key).value = (value.filter || {})[key] ?? ''; });
            Array.from(field('availabilityStatus').options).forEach((option) => { option.selected = ((value.filter || {}).availabilityStatus || []).includes(option.value); });
        }
        warehouses.addEventListener('change', refresh);
        return { read, apply, refresh };
    };
}());
