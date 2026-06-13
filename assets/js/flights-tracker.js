(function () {
  const config = window.FlightsTracker || {};

  const post = async (action, data = {}) => {
    const body = new URLSearchParams({
      action,
      nonce: config.nonce,
      ...data,
    });

    const response = await fetch(config.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body,
    });

    const payload = await response.json();

    if (!payload.success) {
      throw new Error(payload.data?.message || 'No se ha podido completar la accion.');
    }

    return payload.data;
  };

  const text = (value, fallback = '-') => {
    const clean = String(value || '').trim();
    return clean || fallback;
  };

  const escapeHtml = (value) =>
    String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');

  const flightCard = (flight, options = {}) => {
    const saveButton = options.canSave
      ? `<button class="ft-button ft-button--save" type="button" data-ft-save="${flight.id}">Guardar</button>`
      : '';
    const removeButton = options.savedId
      ? `<button class="ft-button ft-button--danger" type="button" data-ft-delete-saved="${options.savedId}">Eliminar</button>`
      : '';

    return `
      <article class="ft-card ft-card--${escapeHtml(flight.direction)}">
        <div class="ft-card__top">
          <span class="ft-badge ft-badge--${escapeHtml(flight.direction)}">${escapeHtml(flight.directionLabel)}</span>
          <strong class="ft-flight-number">${escapeHtml(text(flight.flightNumberDisplay || flight.flightNumber))}</strong>
          <strong class="ft-card__route">${escapeHtml(text(flight.route))}</strong>
          <div class="ft-card__actions">${saveButton}${removeButton}</div>
        </div>
        <div class="ft-grid">
          <div><span>Compania</span><strong>${escapeHtml(text(flight.airline))}</strong></div>
          <div><span>Matricula</span><strong>${escapeHtml(text(flight.registration))}</strong></div>
          <div><span>Prog.</span><strong>${escapeHtml(text(flight.scheduledTime))}</strong></div>
          <div><span>Real</span><strong>${escapeHtml(text(flight.realTime))}</strong></div>
          <div class="ft-grid__wide"><span>Estado</span><strong class="ft-status ft-status--${escapeHtml(flight.statusType)}">${escapeHtml(text(flight.status))}</strong></div>
        </div>
      </article>
    `;
  };

  const savedCard = (item) => {
    const related = item.related
      ? `<div class="ft-pair__divider">Relacionado</div>${flightCard(item.related, {})}`
      : '<div class="ft-empty ft-empty--small">No hay vuelo relacionado guardado.</div>';

    return `
      <section class="ft-pair" data-ft-saved-item="${item.id}">
        <div class="ft-pair__meta">
          <span>Guardado ${escapeHtml(text(item.createdAt))}</span>
          <button class="ft-button ft-button--danger" type="button" data-ft-delete-saved="${item.id}">Eliminar</button>
        </div>
        ${flightCard(item.primary, {})}
        ${related}
      </section>
    `;
  };

  const setAlert = (root, message, type = 'info') => {
    const alert = root.querySelector('[data-ft-alert]');
    if (!alert) return;
    alert.textContent = message || '';
    alert.className = `ft-alert ft-alert--${type}`;
    alert.hidden = !message;
  };

  const renderPagination = (root, pagination) => {
    const container = root.querySelector('[data-ft-pagination]');
    if (!container || !pagination || pagination.pages <= 1) {
      if (container) {
        container.hidden = true;
        container.innerHTML = '';
      }
      return;
    }

    container.hidden = false;
    container.innerHTML = `
      <button class="ft-button ft-button--ghost" type="button" data-ft-page="${pagination.page - 1}" ${pagination.page <= 1 ? 'disabled' : ''}>Anterior</button>
      <span>Pagina ${pagination.page} de ${pagination.pages}</span>
      <button class="ft-button ft-button--ghost" type="button" data-ft-page="${pagination.page + 1}" ${pagination.page >= pagination.pages ? 'disabled' : ''}>Siguiente</button>
    `;
  };

  const initTracker = (root) => {
    const form = root.querySelector('[data-ft-search-form]');
    const input = root.querySelector('[data-ft-query]');
    const dateFrom = root.querySelector('[data-ft-date-from]');
    const dateTo = root.querySelector('[data-ft-date-to]');
    const direction = root.querySelector('[data-ft-direction]');
    const results = root.querySelector('[data-ft-results]');
    const summary = root.querySelector('[data-ft-summary]');
    const refreshButton = root.querySelector('[data-ft-refresh]');
    const modal = root.querySelector('[data-ft-modal]');
    const modalIntro = root.querySelector('[data-ft-modal-intro]');
    const matchList = root.querySelector('[data-ft-match-list]');
    const closeModal = root.querySelector('[data-ft-modal-close]');
    const table = root.dataset.table || config.table || '';
    const perPage = root.dataset.perPage || config.perPage || 25;

    const state = {
      query: '',
      page: 1,
      loading: false,
    };

    const load = async (page = state.page) => {
      if (state.loading) return;
      state.loading = true;
      state.page = Math.max(1, Number(page) || 1);
      summary.textContent = 'Actualizando vuelos...';

      try {
        const data = await post('flights_tracker_search', {
          query: state.query,
          base: root.dataset.base || config.base || 'AGP',
          table,
          direction: direction.value,
          dateFrom: dateFrom.value,
          dateTo: dateTo.value,
          page: state.page,
          perPage,
        });

        results.innerHTML = data.flights.length
          ? data.flights.map((flight) => flightCard(flight, { canSave: true })).join('')
          : '<div class="ft-empty">No hay vuelos para esta busqueda.</div>';
        summary.textContent = `${data.pagination.total} vuelos · ${data.rangeLabel} · actualizado ${data.serverTime}`;
        renderPagination(root, data.pagination);
        setAlert(root, '');
      } catch (error) {
        summary.textContent = 'No se han podido actualizar los vuelos.';
        setAlert(root, error.message, 'error');
      } finally {
        state.loading = false;
      }
    };

    const openMatches = async (flightId) => {
      modal.hidden = false;
      modalIntro.textContent = 'Buscando vuelos con la misma matricula...';
      matchList.innerHTML = '';

      try {
        const data = await post('flights_tracker_matches', { flightId, table });
        const flight = data.flight;

        modalIntro.textContent = `${flight.registration || 'Sin matricula'} · ${flight.flightNumber}. Elige el vuelo relacionado que quieres guardar.`;

        if (!data.isLoggedIn) {
          matchList.innerHTML = `
            <div class="ft-empty">
              Para guardar vuelos necesitas iniciar sesion.
              <a href="${escapeHtml(config.loginUrl)}">Iniciar sesion</a>
            </div>
          `;
          return;
        }

        if (!data.matches.length) {
          matchList.innerHTML = '<div class="ft-empty">No hay vuelos relacionados para esta matricula y hora.</div>';
          return;
        }

        matchList.innerHTML = data.matches
          .map((match) => `
            <div class="ft-match">
              ${flightCard(match, {})}
              <button class="ft-button ft-button--primary" type="button" data-ft-confirm-save="${flight.id}" data-ft-related="${match.id}">
                Guardar esta combinacion
              </button>
            </div>
          `)
          .join('');
      } catch (error) {
        modalIntro.textContent = '';
        matchList.innerHTML = `<div class="ft-empty">${escapeHtml(error.message)}</div>`;
      }
    };

    form.addEventListener('submit', (event) => {
      event.preventDefault();
      state.query = input.value.trim();
      load(1);
    });

    [dateFrom, dateTo, direction].forEach((control) => {
      control.addEventListener('change', () => {
        state.query = input.value.trim();
        load(1);
      });
    });

    refreshButton.addEventListener('click', () => load(state.page));
    closeModal.addEventListener('click', () => {
      modal.hidden = true;
    });

    modal.addEventListener('click', (event) => {
      if (event.target === modal) {
        modal.hidden = true;
      }
    });

    root.addEventListener('click', async (event) => {
      const save = event.target.closest('[data-ft-save]');
      const confirm = event.target.closest('[data-ft-confirm-save]');
      const page = event.target.closest('[data-ft-page]');

      if (page) {
        load(page.dataset.ftPage);
      }

      if (save) {
        openMatches(save.dataset.ftSave);
      }

      if (confirm) {
        confirm.disabled = true;
        confirm.textContent = 'Guardando...';

        try {
          await post('flights_tracker_save', {
            primaryFlightId: confirm.dataset.ftConfirmSave,
            relatedFlightId: confirm.dataset.ftRelated,
            table,
          });
          modal.hidden = true;
          setAlert(root, 'Vuelo guardado correctamente.', 'success');
        } catch (error) {
          confirm.disabled = false;
          confirm.textContent = 'Guardar esta combinacion';
          setAlert(root, error.message, 'error');
        }
      }
    });

    input.addEventListener('input', () => {
      if (input.value.trim() === '' && state.query !== '') {
        state.query = '';
        load(1);
      }
    });

    load(1);
    window.setInterval(() => load(state.page), Number(config.refreshMs || 60000));
  };

  const initSaved = (root) => {
    const results = root.querySelector('[data-ft-saved-results]');
    const summary = root.querySelector('[data-ft-saved-summary]');
    const refresh = root.querySelector('[data-ft-saved-refresh]');
    const table = root.dataset.table || config.table || '';

    const load = async () => {
      summary.textContent = 'Actualizando tus vuelos guardados...';

      try {
        const data = await post('flights_tracker_saved', { table });
        results.innerHTML = data.saved.length
          ? data.saved.map(savedCard).join('')
          : '<div class="ft-empty">Aun no tienes vuelos guardados.</div>';
        summary.textContent = `${data.saved.length} guardados · actualizado ${data.serverTime}`;
        setAlert(root, '');
      } catch (error) {
        summary.textContent = 'No se han podido cargar tus vuelos guardados.';
        setAlert(root, error.message, 'error');
      }
    };

    root.addEventListener('click', async (event) => {
      const button = event.target.closest('[data-ft-delete-saved]');
      if (!button) return;

      button.disabled = true;
      button.textContent = 'Eliminando...';

      try {
        await post('flights_tracker_delete_saved', { savedId: button.dataset.ftDeleteSaved });
        load();
      } catch (error) {
        button.disabled = false;
        button.textContent = 'Eliminar';
        setAlert(root, error.message, 'error');
      }
    });

    refresh.addEventListener('click', load);
    load();
    window.setInterval(load, Number(config.refreshMs || 60000));
  };

  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-ft-app]').forEach(initTracker);
    document.querySelectorAll('[data-ft-saved-app]').forEach(initSaved);
  });
})();
