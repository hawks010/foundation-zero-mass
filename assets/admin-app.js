(function () {
  const rootNode = document.getElementById('zmm-admin-app');
  if (!rootNode || !window.wp || !window.wp.element || !window.zmmAdmin) {
    return;
  }

  const { createElement: h, Fragment, useEffect, useMemo, useState } = window.wp.element;

  const toggleFields = [
    ['auto_process_on_upload', 'Auto process uploads', 'Process new image uploads automatically.'],
    ['process_backlog_via_cron', 'Use background queue', 'Queue uploads and let WP-Cron compress them in batches.'],
    ['use_picture_tags', 'Serve modern formats', 'Output AVIF/WebP via <picture> tags on the front end.'],
    ['keep_original_backup', 'Keep originals', 'Store the original file for safe rollback and comparison.'],
    ['auto_generate_alt', 'Generate alt text', 'Create concise descriptive alt text from filenames and parent content.'],
    ['enable_lqip', 'Enable LQIP placeholders', 'Use tiny blurred placeholders while lazy loading large images.'],
  ];

  const qualityOptions = [
    ['recommended', 'Recommended'],
    ['high', 'High'],
    ['highest', 'Highest'],
  ];

  const maintenanceSchedules = [
    ['hourly', 'Hourly'],
    ['daily', 'Daily'],
    ['weekly', 'Weekly'],
  ];

  const queueSchedules = [
    ['zmm_every_fifteen_minutes', 'Every 15 minutes'],
    ['zmm_every_thirty_minutes', 'Every 30 minutes'],
    ['hourly', 'Hourly'],
    ['twicedaily', 'Twice daily'],
    ['daily', 'Daily'],
  ];

  function api(action, payload) {
    const formData = new window.FormData();
    formData.append('action', action);
    formData.append('nonce', window.zmmAdmin.nonce);

    Object.entries(payload || {}).forEach(([key, value]) => {
      if (value && typeof value === 'object' && !(value instanceof window.Blob)) {
        formData.append(key, JSON.stringify(value));
      } else {
        formData.append(key, value);
      }
    });

    return window.fetch(window.zmmAdmin.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      body: formData,
    }).then(async (response) => {
      const data = await response.json();
      if (!response.ok || !data.success) {
        throw new Error(data?.data?.message || 'Request failed.');
      }
      return data.data;
    });
  }

  function readSettings(settings) {
    return {
      auto_process_on_upload: settings.auto_process_on_upload === '1',
      process_backlog_via_cron: settings.process_backlog_via_cron === '1',
      use_picture_tags: settings.use_picture_tags === '1',
      keep_original_backup: settings.keep_original_backup === '1',
      auto_generate_alt: settings.auto_generate_alt === '1',
      enable_lqip: settings.enable_lqip === '1',
      overall_quality: settings.overall_quality || 'recommended',
      max_width: settings.max_width || 1920,
      max_height: settings.max_height || 1920,
      queue_batch_size: settings.queue_batch_size || 3,
      queue_schedule: settings.queue_schedule || 'zmm_every_fifteen_minutes',
      cron_schedule: settings.cron_schedule || 'daily',
      backup_cleanup_days: settings.backup_cleanup_days || 30,
    };
  }

  function serializeSettings(settings) {
    const output = {};
    Object.entries(settings).forEach(([key, value]) => {
      output[key] = typeof value === 'boolean' ? (value ? '1' : '0') : String(value);
    });
    return output;
  }

  function StatCard(props) {
    return h('div', { className: 'zmm-stat' }, [
      h('div', { key: 'label', className: 'zmm-stat-label' }, props.label),
      h('div', { key: 'value', className: 'zmm-stat-value' }, props.value),
    ]);
  }

  function ToggleField(props) {
    return h('label', { className: 'zmm-toggle' }, [
      h('input', {
        key: 'input',
        type: 'checkbox',
        checked: props.checked,
        onChange: (event) => props.onChange(event.target.checked),
      }),
      h('div', { key: 'copy', className: 'space-y-1' }, [
        h('div', { key: 'label', className: 'zmm-label' }, props.label),
        h('div', { key: 'hint', className: 'zmm-hint' }, props.hint),
      ]),
    ]);
  }

  function Field(props) {
    return h('label', { className: 'zmm-control' }, [
      h('span', { key: 'label', className: 'zmm-label' }, props.label),
      props.children,
      props.hint ? h('span', { key: 'hint', className: 'zmm-hint' }, props.hint) : null,
    ]);
  }

  function App() {
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [queueing, setQueueing] = useState(false);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const [payload, setPayload] = useState(null);
    const [settings, setSettings] = useState(null);

    const refresh = () => {
      setLoading(true);
      setError('');
      return api('zmm_get_dashboard_data', {}).then((data) => {
        setPayload(data);
        setSettings(readSettings(data.settings));
      }).catch((err) => setError(err.message)).finally(() => setLoading(false));
    };

    useEffect(() => {
      refresh();
    }, []);

    const formattedStats = useMemo(() => {
      if (!payload) {
        return [];
      }

      return [
        ['Current library size', formatBytes(payload.stats.current_size || 0)],
        ['Space saved', formatBytes(payload.stats.total_savings || 0)],
        ['Queued', payload.queue.queued || 0],
        ['Needs processing', payload.queue.unprocessed || 0],
      ];
    }, [payload]);

    const save = () => {
      setSaving(true);
      setError('');
      setNotice('');
      return api('zmm_save_settings', { settings: serializeSettings(settings) }).then((data) => {
        setPayload(data);
        setSettings(readSettings(data.settings));
        setNotice('Settings saved. Queue and maintenance schedules were refreshed.');
      }).catch((err) => setError(err.message)).finally(() => setSaving(false));
    };

    const queueAll = () => {
      setQueueing(true);
      setError('');
      setNotice('');
      return api('zmm_queue_all_unprocessed', {}).then((data) => {
        setNotice(data.message);
        setPayload((current) => current ? Object.assign({}, current, { queue: data.queue }) : current);
      }).catch((err) => setError(err.message)).finally(() => setQueueing(false));
    };

    if (loading || !settings || !payload) {
      return h('div', { className: 'zmm-app zmm-shell' }, h('div', { className: 'zmm-card' }, 'Loading Zero Mass…'));
    }

    return h('div', { className: 'zmm-app' },
      h('div', { className: 'zmm-shell' }, [
        h('section', { key: 'hero', className: 'zmm-hero' }, [
          h('span', { key: 'eyebrow', className: 'zmm-pill' }, 'Foundation: Zero Mass'),
          h('div', { key: 'titleWrap', className: 'mt-4 space-y-3' }, [
            h('h1', { key: 'title', className: 'text-3xl font-semibold tracking-tight text-slate-950' }, 'Media compression that can finally run in the background'),
            h('p', { key: 'copy', className: 'max-w-3xl text-base leading-7 text-slate-600' }, 'This pass modernises Zero Mass into a safer queue-driven optimizer: uploads can be queued, cron can process the backlog automatically, and the settings page is now ready for a React admin shell with a Tailwind-based layout.'),
          ]),
        ]),
        error ? h('div', { key: 'error', className: 'zmm-alert zmm-alert-error' }, error) : null,
        notice ? h('div', { key: 'notice', className: 'zmm-alert zmm-alert-success' }, notice) : null,
        h('div', { key: 'grid', className: 'zmm-grid' }, [
          h('div', { key: 'main', className: 'zmm-stack' }, [
            h('section', { key: 'stats', className: 'zmm-card' }, [
              h('h2', { key: 'title', className: 'zmm-card-title' }, 'Library overview'),
              h('div', { key: 'statsGrid', className: 'zmm-stat-grid' },
                formattedStats.map(([label, value]) => h(StatCard, { key: label, label, value }))
              ),
              h('div', { key: 'queueMeta', className: 'mt-5 flex flex-wrap gap-3 text-sm text-slate-500' }, [
                h('span', { key: 'processing', className: 'zmm-pill' }, 'Processing: ' + payload.queue.processing),
                h('span', { key: 'failed', className: 'zmm-pill' }, 'Failed: ' + payload.queue.failed),
                h('span', { key: 'webp', className: 'zmm-pill' }, 'WebP: ' + (payload.support.webp ? 'Supported' : 'Unavailable')),
                h('span', { key: 'avif', className: 'zmm-pill' }, 'AVIF: ' + (payload.support.avif ? 'Supported' : 'Unavailable')),
              ]),
            ]),
            h('section', { key: 'settings', className: 'zmm-card' }, [
              h('h2', { key: 'title', className: 'zmm-card-title' }, 'Optimization settings'),
              h('div', { key: 'toggles', className: 'mt-5 grid gap-4 lg:grid-cols-2' },
                toggleFields.map(([name, label, hint]) => h(ToggleField, {
                  key: name,
                  checked: settings[name],
                  label,
                  hint,
                  onChange: (checked) => setSettings((current) => Object.assign({}, current, { [name]: checked })),
                }))
              ),
              h('div', { key: 'fields', className: 'zmm-form-grid' }, [
                h(Field, { key: 'quality', label: 'Compression profile', hint: 'Applies format-aware quality values for JPEG, WebP, and AVIF.' },
                  h('select', {
                    className: 'zmm-select',
                    value: settings.overall_quality,
                    onChange: (event) => setSettings((current) => Object.assign({}, current, { overall_quality: event.target.value })),
                  }, qualityOptions.map(([value, label]) => h('option', { key: value, value }, label)))
                ),
                h(Field, { key: 'batch', label: 'Queue batch size', hint: 'How many images to process during each cron run.' },
                  h('input', {
                    className: 'zmm-input',
                    type: 'number',
                    min: '1',
                    max: '25',
                    value: settings.queue_batch_size,
                    onChange: (event) => setSettings((current) => Object.assign({}, current, { queue_batch_size: event.target.value })),
                  })
                ),
                h(Field, { key: 'width', label: 'Max width' },
                  h('input', {
                    className: 'zmm-input',
                    type: 'number',
                    min: '320',
                    value: settings.max_width,
                    onChange: (event) => setSettings((current) => Object.assign({}, current, { max_width: event.target.value })),
                  })
                ),
                h(Field, { key: 'height', label: 'Max height' },
                  h('input', {
                    className: 'zmm-input',
                    type: 'number',
                    min: '320',
                    value: settings.max_height,
                    onChange: (event) => setSettings((current) => Object.assign({}, current, { max_height: event.target.value })),
                  })
                ),
                h(Field, { key: 'maintenance', label: 'Maintenance schedule' },
                  h('select', {
                    className: 'zmm-select',
                    value: settings.cron_schedule,
                    onChange: (event) => setSettings((current) => Object.assign({}, current, { cron_schedule: event.target.value })),
                  }, maintenanceSchedules.map(([value, label]) => h('option', { key: value, value }, label)))
                ),
                h(Field, { key: 'queueSchedule', label: 'Queue schedule' },
                  h('select', {
                    className: 'zmm-select',
                    value: settings.queue_schedule,
                    onChange: (event) => setSettings((current) => Object.assign({}, current, { queue_schedule: event.target.value })),
                  }, queueSchedules.map(([value, label]) => h('option', { key: value, value }, label)))
                ),
                h(Field, { key: 'cleanup', label: 'Backup cleanup window (days)' },
                  h('input', {
                    className: 'zmm-input',
                    type: 'number',
                    min: '0',
                    value: settings.backup_cleanup_days,
                    onChange: (event) => setSettings((current) => Object.assign({}, current, { backup_cleanup_days: event.target.value })),
                  })
                ),
              ]),
              h('div', { key: 'actions', className: 'zmm-actions' }, [
                h('button', {
                  key: 'save',
                  className: 'zmm-button-primary',
                  onClick: save,
                  disabled: saving,
                  type: 'button',
                }, saving ? 'Saving…' : 'Save settings'),
                h('button', {
                  key: 'queue',
                  className: 'zmm-button-secondary',
                  onClick: queueAll,
                  disabled: queueing,
                  type: 'button',
                }, queueing ? 'Queueing…' : 'Queue all unprocessed images'),
              ]),
            ]),
          ]),
          h('div', { key: 'sidebar', className: 'zmm-stack' }, [
            h('section', { key: 'queueCard', className: 'zmm-card' }, [
              h('h2', { key: 'title', className: 'zmm-card-title' }, 'Background queue'),
              h('p', { key: 'copy', className: 'mt-3 text-sm leading-6 text-slate-600' }, 'Uploads can now be queued for later compression instead of always blocking on upload. This is the safest route for larger libraries and slower image editors.'),
              h('div', { key: 'queueList', className: 'mt-5 space-y-3 text-sm text-slate-600' }, [
                h('div', { key: 'queued' }, 'Queued: ' + payload.queue.queued),
                h('div', { key: 'processing' }, 'Processing: ' + payload.queue.processing),
                h('div', { key: 'failed' }, 'Failed: ' + payload.queue.failed),
                h('div', { key: 'backlog' }, 'Awaiting first pass: ' + payload.queue.unprocessed),
              ]),
            ]),
            h('section', { key: 'notes', className: 'zmm-card' }, [
              h('h2', { key: 'title', className: 'zmm-card-title' }, 'What changed in this pass'),
              h('ul', { key: 'list', className: 'mt-4 list-disc space-y-2 pl-5 text-sm leading-6 text-slate-600' }, [
                h('li', { key: 'item-1' }, 'Compression now saves to a work file first, then only replaces the original when it actually improves the file or dimensions.'),
                h('li', { key: 'item-2' }, 'Attachment metadata is regenerated after a successful image rewrite.'),
                h('li', { key: 'item-3' }, 'Alt text generation now avoids generic “image in article” filler phrasing.'),
                h('li', { key: 'item-4' }, 'The settings page is now served by a React shell backed by AJAX endpoints instead of a classic WordPress settings form.'),
              ]),
            ]),
          ]),
        ]),
      ])
    );
  }

  function formatBytes(bytes) {
    if (!bytes) {
      return '0 B';
    }
    const units = ['B', 'KB', 'MB', 'GB'];
    let value = bytes;
    let index = 0;
    while (value >= 1024 && index < units.length - 1) {
      value /= 1024;
      index += 1;
    }
    return value.toFixed(index === 0 ? 0 : 1) + ' ' + units[index];
  }

  const root = window.wp.element.createRoot ? window.wp.element.createRoot(rootNode) : null;
  if (root) {
    root.render(h(App));
  } else {
    window.wp.element.render(h(App), rootNode);
  }
})();
