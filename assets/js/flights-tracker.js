(function () {
  const config = window.FlightsTracker || {};

  const ajaxUrl = (root) => {
    const rawUrl = root?.dataset.ftAjaxUrl || config.ajaxUrl || '/wp-admin/admin-ajax.php';

    try {
      return new URL(rawUrl, window.location.href).toString();
    } catch (error) {
      throw new Error('No se ha podido preparar la conexion con WordPress. Revisa la URL de admin-ajax.php.');
    }
  };

  const nonce = (root) => root?.dataset.ftNonce || config.nonce || '';

  const post = async (action, data = {}, root = null) => {
    const body = new URLSearchParams({
      action,
      nonce: nonce(root),
      ...data,
    });

    const response = await fetch(ajaxUrl(root), {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body,
    });

    const responseText = await response.text();
    let payload = null;

    try {
      payload = JSON.parse(responseText);
    } catch (error) {
      throw new Error('WordPress no ha devuelto una respuesta valida. Revisa si la pagina redirige, si falta iniciar sesion o si admin-ajax.php esta bloqueado.');
    }

    if (!response.ok) {
      throw new Error(payload.data?.message || `WordPress ha respondido con error ${response.status}.`);
    }

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

  const preferredTime = (flight) => flight?.realTime || flight?.scheduledTime || '-';

  const compactTime = (flight) => {
    if (flight?.direction === 'arrival' && flight?.statusType === 'landed') {
      return { label: 'Landed', type: 'landed' };
    }

    return { label: preferredTime(flight), type: '' };
  };

  const compactFlightsTable = (item) => {
    const flights = [item.arrival, item.departure].filter(Boolean);

    if (!flights.length) {
      return '';
    }

    const columnClass = flights.length === 1 ? 'ft-pair__flight-table--one' : 'ft-pair__flight-table--two';
    const numberCells = flights
      .map((flight) => `<span class="ft-pair__flight-number">${escapeHtml(text(flight.flightNumberDisplay || flight.flightNumber))}</span>`)
      .join('');
    const timeCells = flights
      .map((flight) => {
        const time = compactTime(flight);
        const typeClass = time.type ? ` ft-pair__flight-time--${escapeHtml(time.type)}` : '';

        return `<span class="ft-pair__flight-time${typeClass}">${escapeHtml(text(time.label))}</span>`;
      })
      .join('');

    return `
      <span class="ft-pair__flight-table ${columnClass}" aria-label="Vuelos y horarios">
        ${numberCells}
        ${timeCells}
      </span>
    `;
  };

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

  const savedCard = (item, openIds = new Set()) => {
    const arrival = item.arrival ? flightCard(item.arrival, {}) : '';
    const departure = item.departure ? flightCard(item.departure, {}) : '';
    const fallback = !arrival && !departure ? '<div class="ft-empty ft-empty--small">No hay vuelos disponibles para este guardado.</div>' : '';
    const compactTable = compactFlightsTable(item);
    const airline = item.arrival?.airline || item.departure?.airline || item.primary?.airline || item.related?.airline || '-';
    const registration = item.arrival?.registration || item.departure?.registration || item.primary?.registration || item.related?.registration || '-';
    const completedClass = item.completed ? ' ft-pair--completed' : '';
    const completedText = item.completed ? 'Pendiente' : 'Realizado';
    const completedValue = item.completed ? '0' : '1';
    const completedInfo = item.completed
      ? `<span class="ft-pair__completed">Realizado ${escapeHtml(text(item.completedAt, 'sin hora'))}</span>`
      : '<span class="ft-pair__completed">Pendiente</span>';
    const openAttribute = openIds.has(String(item.id)) ? ' open' : '';

    return `
      <details class="ft-pair${completedClass}" data-ft-saved-item="${item.id}"${openAttribute}>
        <summary class="ft-pair__summary">
          <span class="ft-pair__saved-at">Guardado ${escapeHtml(text(item.createdAt))}</span>
          <span class="ft-pair__airline">${escapeHtml(text(airline))}</span>
          ${compactTable}
          <strong class="ft-pair__registration">${escapeHtml(text(registration))}</strong>
          ${completedInfo}
          <span class="ft-pair__toggle">Desplegar</span>
        </summary>
        <div class="ft-pair__body">
          ${arrival}
          ${departure}
          ${fallback}
          <div class="ft-pair__actions">
            <button class="ft-button ft-button--danger" type="button" data-ft-delete-saved="${item.id}">Eliminar</button>
            <button class="ft-button ft-button--done" type="button" data-ft-complete-saved="${item.id}" data-ft-completed="${completedValue}">${completedText}</button>
          </div>
        </div>
      </details>
    `;
  };

  const archivedCard = (item, openIds = new Set()) => {
    const arrival = item.arrival ? flightCard(item.arrival, {}) : '';
    const departure = item.departure ? flightCard(item.departure, {}) : '';
    const fallback = !arrival && !departure ? '<div class="ft-empty ft-empty--small">No hay datos guardados para este archivo.</div>' : '';
    const compactTable = compactFlightsTable(item);
    const airline = item.airline || item.arrival?.airline || item.departure?.airline || '-';
    const registration = item.registration || item.arrival?.registration || item.departure?.registration || '-';
    const completedInfo = item.completedAt
      ? `<span class="ft-pair__completed">Realizado ${escapeHtml(text(item.completedAt))}</span>`
      : '<span class="ft-pair__completed">Realizado -</span>';
    const openAttribute = openIds.has(String(item.id)) ? ' open' : '';

    return `
      <details class="ft-pair ft-pair--archived" data-ft-archived-item="${item.id}"${openAttribute}>
        <summary class="ft-pair__summary">
          <span class="ft-pair__saved-at">Archivado ${escapeHtml(text(item.archivedAt))}</span>
          <span class="ft-pair__airline">${escapeHtml(text(airline))}</span>
          ${compactTable}
          <strong class="ft-pair__registration">${escapeHtml(text(registration))}</strong>
          ${completedInfo}
          <span class="ft-pair__toggle">Desplegar</span>
        </summary>
        <div class="ft-pair__body">
          ${arrival}
          ${departure}
          ${fallback}
          <div class="ft-archive-meta">
            <span>Guardado ${escapeHtml(text(item.createdAt))}</span>
            <span>Fecha vuelo ${escapeHtml(text(item.flightDate))}</span>
          </div>
          <div class="ft-pair__actions ft-pair__actions--single">
            <button class="ft-button ft-button--danger" type="button" data-ft-delete-archived="${item.id}">Eliminar archivado</button>
          </div>
        </div>
      </details>
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
    const containers = root.querySelectorAll('[data-ft-pagination]');
    if (!containers.length) return;

    if (!pagination || pagination.pages <= 1) {
      containers.forEach((container) => {
        container.hidden = true;
        container.innerHTML = '';
      });
      return;
    }

    const markup = `
      <button class="ft-button ft-button--ghost" type="button" data-ft-offset="${pagination.previousOffset}" ${pagination.offset <= 0 ? 'disabled' : ''}>Anterior</button>
      <span>Pagina ${pagination.page} de ${pagination.pages}</span>
      <button class="ft-button ft-button--ghost" type="button" data-ft-offset="${pagination.nextOffset}" ${pagination.nextOffset >= pagination.total ? 'disabled' : ''}>Siguiente</button>
    `;
    containers.forEach((container) => {
      container.hidden = false;
      container.innerHTML = markup;
    });
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
      offset: null,
      loading: false,
    };

    const load = async (page = state.page, options = {}) => {
      if (state.loading) return;
      state.loading = true;

      if (options.resetOffset) {
        state.offset = null;
      } else if (Object.prototype.hasOwnProperty.call(options, 'offset')) {
        state.offset = Math.max(0, Number(options.offset) || 0);
      }

      state.page = Math.max(1, Number(page) || state.page || 1);
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
          offset: state.offset === null ? '' : state.offset,
          initialPage: options.initialPage ? '1' : '0',
        }, root);

        results.innerHTML = data.flights.length
          ? data.flights.map((flight) => flightCard(flight, { canSave: true })).join('')
          : '<div class="ft-empty">No hay vuelos para esta busqueda.</div>';
        summary.textContent = `${data.pagination.total} vuelos · ${data.rangeLabel} · actualizado ${data.serverTime}`;
        state.page = data.pagination.page;
        state.offset = data.pagination.offset;
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
        const data = await post('flights_tracker_matches', { flightId, table }, root);
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
      load(1, { resetOffset: true });
    });

    [dateFrom, dateTo, direction].forEach((control) => {
      control.addEventListener('change', () => {
        state.query = input.value.trim();
        load(1, { resetOffset: true });
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
      const offset = event.target.closest('[data-ft-offset]');

      if (page) {
        load(page.dataset.ftPage, { resetOffset: true });
      }

      if (offset) {
        load(state.page, { offset: offset.dataset.ftOffset });
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
          }, root);
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
        load(1, { resetOffset: true });
      }
    });

    load(1, { initialPage: true });
    window.setInterval(() => load(state.page), Number(config.refreshMs || 60000));
  };

  const initSaved = (root) => {
    const results = root.querySelector('[data-ft-saved-results]');
    const summary = root.querySelector('[data-ft-saved-summary]');
    const refresh = root.querySelector('[data-ft-saved-refresh]');
    const archivedResults = root.querySelector('[data-ft-archived-results]');
    const archivedSummary = root.querySelector('[data-ft-archived-summary]');
    const archivedRefresh = root.querySelector('[data-ft-archived-refresh]');
    const archivedPdf = root.querySelector('[data-ft-archived-pdf]');
    const table = root.dataset.table || config.table || '';

    const openSavedIds = () =>
      new Set(Array.from(results.querySelectorAll('[data-ft-saved-item][open]')).map((item) => item.dataset.ftSavedItem));

    const openArchivedIds = () =>
      new Set(Array.from(archivedResults.querySelectorAll('[data-ft-archived-item][open]')).map((item) => item.dataset.ftArchivedItem));

    const loadArchived = async () => {
      if (!archivedResults || !archivedSummary) return;

      archivedSummary.textContent = 'Actualizando vuelos archivados...';
      const openIds = openArchivedIds();

      try {
        const data = await post('flights_tracker_archived', {}, root);
        archivedResults.innerHTML = data.archived.length
          ? data.archived.map((item) => archivedCard(item, openIds)).join('')
          : '<div class="ft-empty">Aun no tienes vuelos archivados.</div>';
        archivedSummary.textContent = `${data.archived.length} archivados · actualizado ${data.serverTime}`;
        setAlert(root, '');
      } catch (error) {
        archivedSummary.textContent = 'No se han podido cargar los vuelos archivados.';
        setAlert(root, error.message, 'error');
      }
    };

    const load = async () => {
      summary.textContent = 'Actualizando tus vuelos guardados...';
      const openIds = openSavedIds();

      try {
        const data = await post('flights_tracker_saved', { table }, root);
        results.innerHTML = data.saved.length
          ? data.saved.map((item) => savedCard(item, openIds)).join('')
          : '<div class="ft-empty">Aun no tienes vuelos guardados.</div>';
        summary.textContent = `${data.saved.length} guardados · actualizado ${data.serverTime}`;
        setAlert(root, '');
        loadArchived();
      } catch (error) {
        summary.textContent = 'No se han podido cargar tus vuelos guardados.';
        setAlert(root, error.message, 'error');
      }
    };

    root.addEventListener('click', async (event) => {
      const button = event.target.closest('[data-ft-delete-saved]');
      const complete = event.target.closest('[data-ft-complete-saved]');
      const archivedDelete = event.target.closest('[data-ft-delete-archived]');

      if (button) {
        button.disabled = true;
        button.textContent = 'Eliminando...';

        try {
          await post('flights_tracker_delete_saved', { savedId: button.dataset.ftDeleteSaved }, root);
          load();
        } catch (error) {
          button.disabled = false;
          button.textContent = 'Eliminar';
          setAlert(root, error.message, 'error');
        }
      }

      if (archivedDelete) {
        archivedDelete.disabled = true;
        archivedDelete.textContent = 'Eliminando...';

        try {
          await post('flights_tracker_delete_archived', { archivedId: archivedDelete.dataset.ftDeleteArchived }, root);
          loadArchived();
        } catch (error) {
          archivedDelete.disabled = false;
          archivedDelete.textContent = 'Eliminar archivado';
          setAlert(root, error.message, 'error');
        }
      }

      if (complete) {
        complete.disabled = true;
        complete.textContent = complete.dataset.ftCompleted === '1' ? 'Marcando...' : 'Cambiando...';

        try {
          await post('flights_tracker_complete_saved', {
            savedId: complete.dataset.ftCompleteSaved,
            completed: complete.dataset.ftCompleted,
          }, root);
          load();
        } catch (error) {
          complete.disabled = false;
          complete.textContent = complete.dataset.ftCompleted === '1' ? 'Realizado' : 'Pendiente';
          setAlert(root, error.message, 'error');
        }
      }
    });

    refresh.addEventListener('click', load);

    if (archivedRefresh) {
      archivedRefresh.addEventListener('click', loadArchived);
    }

    if (archivedPdf) {
      archivedPdf.addEventListener('click', () => {
        const downloadNonce = nonce(root);

        if (!downloadNonce) {
          setAlert(root, 'No se ha podido preparar la descarga. Recarga la pagina.', 'error');
          return;
        }

        const url = new URL(ajaxUrl(root));
        url.searchParams.set('action', 'flights_tracker_download_archived_pdf');
        url.searchParams.set('nonce', downloadNonce);
        window.location.href = url.toString();
      });
    }

    load();
    window.setInterval(load, Number(config.refreshMs || 60000));
  };

  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-ft-app]').forEach(initTracker);
    document.querySelectorAll('[data-ft-saved-app]').forEach(initSaved);
  });
})();
