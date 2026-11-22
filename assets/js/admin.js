/* Mloun MenuScope - settings page behaviour. */
jQuery(function ($) {
	'use strict';

	/* Drag & drop ordering: top-level menus and submenus. */
	$('#amenuedit-menu-tree').sortable({
		items: '> li.ame-menu-item',
		handle: '.ame-drag-handle',
		placeholder: 'ame-placeholder',
		tolerance: 'pointer',
		axis: 'y'
	});

	$('.ame-submenu').sortable({
		items: '> li.ame-sub-item',
		handle: '.ame-drag-handle',
		placeholder: 'ame-placeholder ame-placeholder-sub',
		tolerance: 'pointer',
		axis: 'y'
	});

	/* Collapsible submenus (collapsed by default via .ame-collapsed). */
	$(document).on('click', '.ame-toggle', function () {
		var $sub = $(this).closest('li.ame-menu-item').find('> ul.ame-submenu');
		$sub.toggleClass('ame-collapsed');
		$(this).find('.dashicons')
			.toggleClass('dashicons-arrow-right-alt2', $sub.hasClass('ame-collapsed'))
			.toggleClass('dashicons-arrow-down-alt2', !$sub.hasClass('ame-collapsed'));
	});

	/* "Block" implies "Hide". */
	function syncBlockState($row) {
		var blocked = $row.find('.ame-block-cb').prop('checked');
		var $hide = $row.find('.ame-hide-cb');
		if (blocked) {
			$hide.prop('checked', true).prop('disabled', true);
			$row.addClass('ame-is-blocked').removeClass('ame-is-hidden');
		} else {
			$hide.prop('disabled', false);
			$row.removeClass('ame-is-blocked');
			$row.toggleClass('ame-is-hidden', $hide.prop('checked'));
		}
	}

	$(document).on('change', '.ame-block-cb', function () {
		syncBlockState($(this).closest('.ame-item-row'));
	});
	$(document).on('change', '.ame-hide-cb', function () {
		$(this).closest('.ame-item-row').toggleClass('ame-is-hidden', this.checked);
	});
	$('.ame-block-cb').each(function () {
		syncBlockState($(this).closest('.ame-item-row'));
	});

	/* Actor selector: role and user dropdowns navigate immediately. */
	$('#amenuedit-role-select, #amenuedit-user-select').on('change', function () {
		var actor = $(this).val();
		var base = $('#amenuedit-actor-base-url').val();
		if (actor && base) {
			window.location.href = base + encodeURIComponent(actor);
		}
	});

	/* Confirm before deleting an actor's settings. */
	$('form.amenuedit-delete-actor').on('submit', function () {
		var msg = (window.ameAdmin && ameAdmin.strings && ameAdmin.strings.confirmDeleteActor)
			? ameAdmin.strings.confirmDeleteActor
			: 'Delete all saved settings for this role/user?';
		return window.confirm(msg);
	});
});
