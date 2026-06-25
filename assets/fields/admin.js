jQuery(function ($) {

	/* ─── Image picker (single) ───────────────────────────────────── */
	$('.wpvibe-field-image').each(function () {
		const $wrap    = $(this);
		const $input   = $wrap.find('input[type="hidden"]');
		const $preview = $wrap.find('.wpvibe-field-image-preview');
		const $img     = $preview.find('img');
		const $clear   = $wrap.find('.wpvibe-field-image-clear');
		let frame;
		$wrap.find('.wpvibe-field-image-pick').on('click', function (e) {
			e.preventDefault();
			if (!frame) {
				frame = wp.media({ title: 'Choose image', button: { text: 'Use this image' }, library: { type: 'image' }, multiple: false });
				frame.on('select', function () {
					const a = frame.state().get('selection').first().toJSON();
					const url = (a.sizes && a.sizes.medium) ? a.sizes.medium.url : a.url;
					$input.val(a.id);
					$img.attr('src', url);
					$preview.show();
					$clear.show();
				});
			}
			frame.open();
		});
		$clear.on('click', function (e) {
			e.preventDefault();
			$input.val(''); $preview.hide(); $clear.hide();
		});
	});

	/* ─── Gallery (multi-image, sortable) ─────────────────────────── */
	$('.wpvibe-field-gallery').each(function () {
		const $wrap  = $(this);
		const $input = $wrap.find('input[type="hidden"]');
		const $grid  = $wrap.find('.wpvibe-field-gallery-grid');
		let frame;
		function sync() {
			const ids = [];
			$grid.find('.wpvibe-field-gallery-item').each(function () { ids.push($(this).attr('data-id')); });
			$input.val(ids.join(','));
		}
		$grid.sortable({ items: '.wpvibe-field-gallery-item', tolerance: 'pointer', update: sync });
		$wrap.find('.wpvibe-field-gallery-add').on('click', function (e) {
			e.preventDefault();
			if (!frame) {
				frame = wp.media({ title: 'Add images', button: { text: 'Add to gallery' }, library: { type: 'image' }, multiple: 'add' });
				frame.on('select', function () {
					frame.state().get('selection').each(function (att) {
						const a = att.attributes;
						const url = (a.sizes && a.sizes.thumbnail) ? a.sizes.thumbnail.url : a.url;
						const $item = $('<div class="wpvibe-field-gallery-item" style="position:relative;aspect-ratio:1;border:1px solid #c3c4c7;border-radius:4px;overflow:hidden;cursor:move"></div>')
							.attr('data-id', a.id)
							.append($('<img>').attr('src', url).css({width:'100%',height:'100%',objectFit:'cover'}))
							.append($('<button type="button" class="wpvibe-field-gallery-remove" style="position:absolute;top:4px;right:4px;background:#1d2327;color:#fff;border:none;border-radius:50%;width:22px;height:22px;cursor:pointer;font-size:14px;line-height:1;padding:0">&times;</button>'));
						$grid.append($item);
					});
					sync();
				});
			}
			frame.open();
		});
		$wrap.on('click', '.wpvibe-field-gallery-remove', function () {
			$(this).closest('.wpvibe-field-gallery-item').remove();
			sync();
		});
	});

	/* ─── Color picker ────────────────────────────────────────────── */
	if ($.fn.wpColorPicker) $('.wpvibe-field-color').wpColorPicker();

	/* ─── Repeater ────────────────────────────────────────────────── */
	$('.wpvibe-field-repeater').each(function () {
		const $rep      = $(this);
		const $input    = $rep.find('.wpvibe-field-repeater-input');
		const $rows     = $rep.find('.wpvibe-field-repeater-rows');
		const $template = $rep.find('.wpvibe-field-repeater-template');

		function sync() {
			const out = [];
			$rows.children('.wpvibe-field-repeater-row').each(function () {
				const row = {};
				let hasValue = false;
				$(this).find('[data-sub]').each(function () {
					const v = $(this).val();
					row[$(this).data('sub')] = v;
					if (v !== '' && v != null) hasValue = true;
				});
				if (hasValue) out.push(row);
			});
			$input.val(JSON.stringify(out));
		}

		$rows.sortable({ items: '.wpvibe-field-repeater-row', handle: '.wpvibe-field-repeater-handle', tolerance: 'pointer', update: sync });

		$rep.find('.wpvibe-field-repeater-add').on('click', function (e) {
			e.preventDefault();
			$rows.append($template[0].innerHTML);
		});

		$rep.on('click', '.wpvibe-field-repeater-remove', function (e) {
			$(this).closest('.wpvibe-field-repeater-row').remove();
			sync();
		});

		$rep.on('input change', '.wpvibe-field-repeater-row [data-sub]', sync);
	});
});
