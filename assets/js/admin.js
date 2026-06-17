(function () {
    function refreshSelectOptions(wrapper) {
        var select = wrapper.querySelector('.wrt-secondary-keyword-select');
        if (!select) {
            return;
        }

        var selected = {};
        wrapper.querySelectorAll('.wrt-selected-secondary-keyword input').forEach(function (input) {
            selected[input.value] = true;
        });

        Array.prototype.forEach.call(select.options, function (option) {
            if (option.value === '') {
                option.hidden = false;
                return;
            }

            option.hidden = !!selected[option.value];
        });

        select.value = '';
    }

    function createSelectedKeyword(wrapper, keyword) {
        var postId = wrapper.getAttribute('data-post-id');
        var selectedList = wrapper.querySelector('.wrt-selected-secondary-keywords');
        if (!postId || !selectedList || keyword === '') {
            return;
        }

        var exists = false;
        selectedList.querySelectorAll('input').forEach(function (input) {
            if (input.value === keyword) {
                exists = true;
            }
        });
        if (exists) {
            return;
        }

        var label = document.createElement('label');
        label.className = 'wrt-keyword-pill wrt-selected-secondary-keyword';

        var input = document.createElement('input');
        input.type = 'checkbox';
        input.name = 'page_keyword_targets[' + postId + '][secondary][]';
        input.value = keyword;
        input.checked = true;

        var text = document.createElement('span');
        text.textContent = keyword;

        var remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'wrt-keyword-remove';
        remove.setAttribute('aria-label', 'Retirer ' + keyword);
        remove.textContent = 'x';

        label.appendChild(input);
        label.appendChild(text);
        label.appendChild(remove);
        selectedList.appendChild(label);
        refreshSelectOptions(wrapper);
    }

    document.addEventListener('change', function (event) {
        var select = event.target.closest('.wrt-secondary-keyword-select');
        if (!select || select.value === '') {
            return;
        }

        var wrapper = select.closest('.wrt-secondary-keyword-picker');
        if (!wrapper) {
            return;
        }

        createSelectedKeyword(wrapper, select.value);
    });

    document.addEventListener('click', function (event) {
        var remove = event.target.closest('.wrt-keyword-remove');
        if (!remove) {
            return;
        }

        var wrapper = remove.closest('.wrt-secondary-keyword-picker');
        var pill = remove.closest('.wrt-selected-secondary-keyword');
        if (pill) {
            pill.remove();
        }
        if (wrapper) {
            refreshSelectOptions(wrapper);
        }
    });

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.wrt-secondary-keyword-picker').forEach(refreshSelectOptions);
    });
})();
