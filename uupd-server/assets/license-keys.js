(function () {
  document.addEventListener('click', function (event) {
    const copy = event.target.closest('[data-uupd-copy]');
    if (copy) {
      if (!navigator.clipboard || !navigator.clipboard.writeText) return;
      navigator.clipboard.writeText(copy.dataset.uupdCopy).then(function () {
        const old = copy.textContent;
        copy.textContent = 'Copied';
        window.setTimeout(function () { copy.textContent = old; }, 1800);
      });
    }
  });
  document.addEventListener('submit', function (event) {
    const prompt = event.target.querySelector('[data-uupd-confirm]');
    if (prompt && !window.confirm(prompt.dataset.uupdConfirm)) event.preventDefault();
  });
  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-uupd-customer-child-browser]').forEach(function (browser) {
      const search = browser.querySelector('[data-uupd-child-search]');
      const status = browser.querySelector('[data-uupd-child-status]');
      const sites = browser.querySelector('[data-uupd-child-sites]');
      const rows = Array.from(browser.querySelectorAll('[data-uupd-child-row]'));
      const count = browser.querySelector('[data-uupd-child-count]');
      const empty = browser.querySelector('[data-uupd-child-empty]');
      function filterRows() {
        const query = search.value.trim().toLocaleLowerCase();
        let visible = 0;
        rows.forEach(function (row) {
          const matches = (!query || row.dataset.uupdSearch.toLocaleLowerCase().includes(query)) &&
            (status.value === 'all' || row.dataset.uupdStatus === status.value) &&
            (sites.value === 'all' || row.dataset.uupdConnected === (sites.value === 'connected' ? '1' : '0'));
          row.hidden = !matches;
          if (matches) visible++;
        });
        count.textContent = visible + ' of ' + rows.length + ' child keys shown';
        empty.hidden = visible !== 0;
      }
      search.addEventListener('input', filterRows);
      status.addEventListener('change', filterRows);
      sites.addEventListener('change', filterRows);
      filterRows();
    });
    const cleanUrl = new URL(window.location.href);
    if (cleanUrl.searchParams.has('uupd_key_result')) {
      cleanUrl.searchParams.delete('uupd_key_result');
      cleanUrl.searchParams.delete('uupd_key_license');
      window.history.replaceState(null, '', cleanUrl.pathname + cleanUrl.search + cleanUrl.hash);
    }
    const manager = document.querySelector('.uupd-key-card:target');
    if (!manager) return;
    manager.closest('details')?.setAttribute('open', '');
    const accordion = manager.closest('.accordion-content');
    if (accordion) accordion.style.display = 'block';
    manager.scrollIntoView({ block: 'center' });
  });
}());
