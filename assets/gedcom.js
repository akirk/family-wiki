/**
 * Choosing what to take out of an uploaded GEDCOM.
 *
 * The whole file is drawn as the tree it will become, with everyone ticked:
 * a file is uploaded to be imported, so the work left is saying which few
 * people to leave out. The table the page falls back to without script is
 * hidden here, and its boxes are what the form still posts.
 *
 * Every line with a branch under it also offers that branch to the front page,
 * which takes a family tree for each one that is ticked.
 */
(function () {
	var review = document.querySelector('.familypedia-gedcom-review');
	if (!review) {
		return;
	}

	var leftOut = review.querySelector('[data-familypedia-gedcom-left-out]');
	var leftOutTemplate = leftOut ? leftOut.textContent.trim() : '';
	var rows = Array.prototype.slice.call(review.querySelectorAll('[data-familypedia-gedcom-row]'));
	var submits = Array.prototype.slice.call(review.querySelectorAll('[data-familypedia-gedcom-submit]'));

	var tree = JSON.parse(review.getAttribute('data-familypedia-gedcom-tree') || '{}');
	var treeBox = review.querySelector('[data-familypedia-gedcom-tree]');
	var treeList = review.querySelector('[data-familypedia-gedcom-tree-list]');
	var treeMore = review.querySelector('[data-familypedia-gedcom-tree-more]');
	var moreTemplate = treeMore.textContent.trim();
	var table = review.querySelector('.familypedia-gedcom-review__table');
	// Only there while the front page is short of a family tree, and what the
	// form posts: the boxes in the lines write it, and it ticks them back.
	var frontRoots = review.querySelector('[data-familypedia-gedcom-front-roots]');
	var settings = JSON.parse(review.getAttribute('data-familypedia-gedcom-settings') || '{}');
	var l10n = settings.l10n || {};
	var uncheckLabel = l10n.uncheck;
	var checkLabel = l10n.check;
	var toggleLabel = l10n.toggle;
	var frontLabel = l10n.front;
	var trashedLabel = l10n.trashed;
	var importAll = l10n.importAll || {};
	var importSome = l10n.importSome || {};
	var importNone = l10n.importNone;
	// Branches are drawn open, so the file reads as the outline the wiki will
	// show once it is imported.
	var folded = Object.create(null);
	// Past the size of any family anyone keeps: this is a backstop against a
	// file that would hang the browser, not a limit meant to be reached. The
	// tree is drawn once and ticking a box only changes the box, so what it
	// guards is the size of the page rather than the cost of a click — and a
	// person left undrawn is a person who cannot be unticked.
	var TREE_LIMIT = 20000;

	// Xrefs come out of the file, so they are never spliced into a
	// selector: every lookup goes through these maps instead. The boxes are
	// held in a list as well, because counting the ticks is done on every
	// tick and finding them again each time is the whole cost of it.
	var boxes = Object.create(null);
	var rowBoxes = [];
	rows.forEach(function (row) {
		var box = row.querySelector('[data-familypedia-gedcom-person]');
		if (box) {
			boxes[box.value] = box;
			rowBoxes.push(box);
		}
	});

	// The box drawn beside each name, the branch buttons with the boxes they
	// take, and the front page box on every line that has a branch under it:
	// all built while the tree is drawn, and all staying put afterwards.
	var nodeBoxes = Object.create(null);
	var branches = [];
	var fronts = [];

	var parentsOf = Object.create(null);
	Object.keys(tree).forEach(function (xref) {
		(tree[xref].children || []).forEach(function (child) {
			parentsOf[child] = parentsOf[child] || [];
			parentsOf[child].push(xref);
		});
	});

	function checked(xref) {
		return !!(boxes[xref] && boxes[xref].checked);
	}

	/**
	 * The table's box is what the form posts, the tree's box is what is
	 * looked at: a person is only ever set through here, so the two agree.
	 */
	function select(xref, on) {
		if (boxes[xref]) {
			boxes[xref].checked = on;
		}
		if (nodeBoxes[xref]) {
			nodeBoxes[xref].checked = on;
		}
	}

	function countSelected() {
		return rowBoxes.filter(function (box) {
			return box.checked;
		}).length;
	}

	function refreshLeftOut(selected) {
		if (!leftOut) {
			return;
		}
		var missing = rowBoxes.length - selected;
		leftOut.hidden = missing < 1;
		if (missing > 0) {
			// Replace the leading number in the rendered, translated string.
			leftOut.textContent = leftOutTemplate.replace(/\d+/, String(missing));
		}
	}

	/**
	 * What the import buttons say. Both of them say the same thing, because
	 * both do the same thing: take whoever is ticked. Ticking everybody is
	 * worth saying out loud, since it is what the page opens on.
	 */
	function refreshSubmits(selected) {
		var plural = selected === 1 ? 'one' : 'other';
		var label = selected === rowBoxes.length
			? importAll[plural]
			: importSome[plural];

		submits.forEach(function (button) {
			button.textContent = selected ? label.replace('%d', selected) : importNone;
			button.disabled = !selected;
		});
	}

	/**
	 * A branch button both takes its branch away and gives it back, so what
	 * it says depends on whether anything under it is still ticked. Ticking
	 * one person changes what the buttons above them offer, which is why
	 * this runs over all of them rather than the one that was pressed.
	 */
	function refreshBranches() {
		branches.forEach(function (branch) {
			var some = branch.targets.some(function (xref) {
				return checked(xref);
			});
			var label = (some ? uncheckLabel : checkLabel)
				+ ' (' + branch.targets.length + ')';

			// Ticking one person leaves what nearly every button says exactly
			// as it was. On a file of thousands the writes are the work, so
			// the last label is kept here rather than read back off the page.
			if (branch.label !== label) {
				branch.label = label;
				branch.button.textContent = label;
			}
		});
	}

	function refresh() {
		var selected = countSelected();
		refreshLeftOut(selected);
		refreshSubmits(selected);
		refreshBranches();

		// A branch nobody is importing cannot lead the front page, so dropping
		// the person a tree was to grow from takes their offer with them.
		if (frontRoots) {
			var dropped = fronts.filter(function (box) {
				return box.checked && !checked(frontOf(box));
			});
			if (dropped.length) {
				dropped.forEach(function (box) {
					box.checked = false;
				});
				syncFronts();
			}
		}
	}

	function person(xref) {
		return tree[xref] || { name: xref };
	}

	function yearRange(entry) {
		if (entry.birth && entry.death) {
			return entry.birth + '–' + entry.death;
		}
		if (entry.birth) {
			return '*' + entry.birth;
		}
		if (entry.death) {
			return '†' + entry.death;
		}

		return '';
	}

	function checkFor(xref) {
		var box = document.createElement('input');
		box.type = 'checkbox';
		box.className = 'familypedia-gedcom-tree__check';
		box.setAttribute('data-familypedia-gedcom-check', xref);
		box.checked = checked(xref);
		nodeBoxes[xref] = box;

		return box;
	}

	/**
	 * The offer of a line to the front page. Any number of them can be
	 * ticked: the front page takes a tree for each, in the order the lines
	 * are drawn, and none at all if none are ticked.
	 */
	function frontFor(xref) {
		var box = document.createElement('input');
		box.type = 'checkbox';
		box.className = 'familypedia-gedcom-tree__front-check';
		box.setAttribute('data-familypedia-gedcom-front', xref);
		fronts.push(box);

		var label = document.createElement('label');
		label.className = 'familypedia-gedcom-tree__front';
		label.appendChild(box);
		label.appendChild(document.createTextNode(frontLabel));

		return label;
	}

	function frontOf(box) {
		return box.getAttribute('data-familypedia-gedcom-front');
	}

	/**
	 * The line somebody is drawn on. Whoever is named may be sharing the line
	 * with the partner it hangs from, and it is the line that carries the box.
	 */
	function frontBoxOn(xref) {
		var owner = nodeBoxes[xref] ? nodeBoxes[xref].closest('li') : null;

		return owner ? owner.querySelector('[data-familypedia-gedcom-front]') : null;
	}

	// What the form posts, read back out of the tree so that the branches are
	// listed down the page, which is the order the front page draws them in.
	function syncFronts() {
		frontRoots.value = Array.prototype.slice
			.call(treeList.querySelectorAll('[data-familypedia-gedcom-front]:checked'))
			.map(frontOf)
			.join(',');
	}

	function chooseFronts(value) {
		var wanted = Object.create(null);
		String(value).split(',').forEach(function (xref) {
			var box = xref ? frontBoxOn(xref) : null;
			if (box) {
				wanted[frontOf(box)] = true;
			}
		});

		fronts.forEach(function (box) {
			box.checked = !!wanted[frontOf(box)];
		});
		syncFronts();
	}

	/**
	 * A person, as the label of their own box: the name is a far bigger target
	 * than the box beside it, and this is a page for ticking people off.
	 */
	function nameOf(xref) {
		var entry = person(xref);
		var label = document.createElement('label');
		label.className = 'familypedia-gedcom-tree__person';
		label.appendChild(checkFor(xref));
		label.appendChild(document.createTextNode(entry.name));

		// Written the way the wiki's own tree writes it: the space stays
		// outside the span, which does not wrap.
		var years = yearRange(entry);
		if (years) {
			var dates = document.createElement('span');
			dates.className = 'familypedia-tree__years';
			dates.textContent = years;
			label.appendChild(document.createTextNode(' '));
			label.appendChild(dates);
		}

		var node = document.createDocumentFragment();
		node.appendChild(label);

		// The page this person is already on was deleted. A mark rather than a
		// sentence: after a wiki is emptied every line carries one, and the
		// footnote under the tree says it once. It sits outside the label, so
		// that reading the mark does not tick the person off.
		if (entry.trashed) {
			var note = document.createElement('sup');
			note.className = 'familypedia-gedcom-tree__note';
			note.textContent = '1';
			note.title = trashedLabel;
			node.appendChild(note);
		}

		return node;
	}

	function byBirth(a, b) {
		// Undated people sort last rather than first, where a missing
		// year would read as the oldest child of every household.
		var left = person(a).birth || '9999';
		var right = person(b).birth || '9999';
		if (left === right) {
			return person(a).name.localeCompare(person(b).name);
		}

		return left < right ? -1 : 1;
	}

	function householdChildren(xref, partners) {
		var seen = Object.create(null);
		var children = [];
		[xref].concat(partners).forEach(function (parent) {
			(person(parent).children || []).forEach(function (child) {
				if (!seen[child]) {
					seen[child] = true;
					children.push(child);
				}
			});
		});

		return children;
	}

	function toggleFor(xref, list) {
		var toggle = document.createElement('button');
		toggle.type = 'button';
		toggle.className = 'familypedia-gedcom-tree__mark familypedia-gedcom-tree__toggle';
		toggle.setAttribute('data-familypedia-gedcom-toggle', xref);
		toggle.setAttribute('aria-label', toggleLabel);
		setOpen(toggle, list, !folded[xref]);

		return toggle;
	}

	function fold(toggle) {
		var xref = toggle.getAttribute('data-familypedia-gedcom-toggle');
		folded[xref] = !folded[xref];
		setOpen(toggle, toggle.closest('li').querySelector('ul'), !folded[xref]);
	}

	function setOpen(toggle, list, open) {
		// The people below stay in the page while folded away: the
		// count on the branch button, and what it takes, are the branch
		// itself and not the part of it that happens to be in view.
		list.hidden = !open;
		toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
		toggle.textContent = open ? '▾' : '▸';
	}

	/**
	 * The branch hanging under a line, and never the couple on the line
	 * itself. Taking the line along with its branch would carry off people
	 * who married in, and with them whichever of their own brothers and
	 * sisters were drawn beneath them; the couple have their own boxes.
	 */
	function branchFor(list, button) {
		var targets = Array.prototype.slice
			.call(list.querySelectorAll('[data-familypedia-gedcom-check]'))
			.map(function (box) {
				return box.getAttribute('data-familypedia-gedcom-check');
			});

		return { button: button, targets: targets };
	}

	function renderPerson(xref, state) {
		if (state.drawn[xref] || state.left < 1) {
			return null;
		}
		state.drawn[xref] = true;
		state.left -= 1;

		var item = document.createElement('li');
		var line = document.createElement('div');
		line.className = 'familypedia-gedcom-tree__line';
		// The names run as one piece of text, however long they get; the front
		// page box is kept out of them so that it can sit at the far end.
		var names = document.createElement('div');
		names.className = 'familypedia-gedcom-tree__names';
		line.appendChild(names);
		names.appendChild(nameOf(xref));

		// The people they married, on the same line: a couple splits
		// their children between them, so drawing them apart would
		// show every household twice with half a family each.
		var partners = (person(xref).partners || []).filter(function (partner) {
			return tree[partner] && !state.drawn[partner];
		});
		// The same mark the wiki's own tree puts between a couple: every
		// family record this comes from becomes a marriage on import.
		partners.forEach(function (partner) {
			state.drawn[partner] = true;
			state.left -= 1;
			names.appendChild(document.createTextNode(' ⚭ '));
			names.appendChild(nameOf(partner));
		});

		item.appendChild(line);

		var children = householdChildren(xref, partners).filter(function (child) {
			return tree[child] && !state.drawn[child];
		}).sort(byBirth);

		if (children.length) {
			var list = document.createElement('ul');
			children.forEach(function (child) {
				var childItem = renderPerson(child, state);
				if (childItem) {
					list.appendChild(childItem);
				}
			});
			if (list.childNodes.length) {
				item.appendChild(list);
				names.insertBefore(toggleFor(xref, list), names.firstChild);
			}
		}

		// A line with nobody under it still gives up the width, so the
		// names down a generation stay under one another.
		if (!item.querySelector('ul')) {
			var spacer = document.createElement('span');
			spacer.className = 'familypedia-gedcom-tree__mark';
			spacer.setAttribute('aria-hidden', 'true');
			names.insertBefore(spacer, names.firstChild);
		}

		// Only now is it known whether anyone hangs off this line, which
		// is what the button offers to take away — and what makes the line
		// worth growing the front page's tree from.
		var branch = item.querySelector('ul');
		if (branch) {
			line.classList.add('familypedia-gedcom-tree__line--branch');

			var button = document.createElement('button');
			button.type = 'button';
			button.className = 'familypedia-gedcom-tree__branch';
			button.setAttribute('data-familypedia-gedcom-branch', '');
			names.appendChild(button);
			branches.push(branchFor(branch, button));

			if (frontRoots) {
				line.appendChild(frontFor(xref));
			}
		}

		return item;
	}

	function renderTree() {
		var state = {
			drawn: Object.create(null),
			left: TREE_LIMIT
		};
		var total = Object.keys(tree).length;

		// Start from everyone the file gives no parents, which is where
		// the branches begin.
		Object.keys(tree).filter(function (xref) {
			return !(parentsOf[xref] || []).length;
		}).sort(byBirth).forEach(function (xref) {
			var item = renderPerson(xref, state);
			if (item) {
				treeList.appendChild(item);
			}
		});

		// Whatever the branches did not reach still has to show up, or
		// people would be imported that the tree never accounted for.
		Object.keys(tree).forEach(function (xref) {
			var item = renderPerson(xref, state);
			if (item) {
				treeList.appendChild(item);
			}
		});

		var missing = total - Object.keys(state.drawn).length;
		treeMore.hidden = missing < 1;
		if (missing > 0) {
			treeMore.textContent = moreTemplate.replace(/\d+/, String(missing));
		}
	}

	review.addEventListener('click', function (event) {
		// Folding changes nothing about the selection, so it moves the
		// one branch rather than touching any boxes. The arrow is the only
		// thing that folds: a name belongs to the box it labels.
		var toggle = event.target.closest('[data-familypedia-gedcom-toggle]');
		if (toggle) {
			fold(toggle);
			return;
		}

		var button = event.target.closest('[data-familypedia-gedcom-branch]');
		if (button) {
			var branch = branches.filter(function (candidate) {
				return candidate.button === button;
			})[0];
			// The button says which way it goes: a branch with anyone
			// left in it is taken away, an empty one comes back whole.
			var on = !branch.targets.some(function (xref) {
				return checked(xref);
			});
			branch.targets.forEach(function (xref) {
				select(xref, on);
			});
			refresh();
		}
	});

	review.addEventListener('change', function (event) {
		if (event.target.closest('[data-familypedia-gedcom-front]')) {
			syncFronts();
			return;
		}

		var box = event.target.closest('[data-familypedia-gedcom-check]');
		if (box) {
			select(box.getAttribute('data-familypedia-gedcom-check'), box.checked);
			refresh();
			return;
		}

		// Without script the table is the only way to pick people, so its
		// own boxes stay wired up while it is in the page.
		if (event.target.closest('[data-familypedia-gedcom-person]')) {
			refresh();
		}
	});

	/*
	 * Importing, a batch at a time.
	 *
	 * A whole family is more work than one request should carry, and a form post
	 * that sits there for a minute looks the same as one that has died. The same
	 * file is walked in pieces instead, and each piece says how far it has got.
	 * Without fetch the form posts itself, which still works.
	 */
	var form = review.querySelector('[data-familypedia-gedcom-form]');
	var progress = review.querySelector('[data-familypedia-gedcom-progress]');
	var bar = progress ? progress.querySelector('[data-familypedia-gedcom-progress-bar]') : null;
	var text = progress ? progress.querySelector('[data-familypedia-gedcom-progress-text]') : null;
	var retryButton = progress ? progress.querySelector('[data-familypedia-gedcom-retry]') : null;
	// The run a failure left off at: the server keeps its place by cursor
	// regardless of why a request never came back, so resuming after a
	// dropped connection is just asking the same run to carry on.
	var lastRun = null;

	var downloadImages = review.querySelector('[data-familypedia-gedcom-download-images]');

	if (form && progress && settings.endpoint && window.fetch) {
		form.addEventListener('submit', function (event) {
			var selected = Array.prototype.slice
				.call(form.querySelectorAll('[data-familypedia-gedcom-person]:checked'))
				.map(function (box) {
					return box.value;
				});

			// Nothing ticked is a mistake the server already words well, and
			// letting the form post keeps that wording in one place.
			if (!selected.length) {
				return;
			}

			event.preventDefault();
			start(selected, frontRoots ? frontRoots.value : '', !!(downloadImages && downloadImages.checked));
		});
	}

	if (retryButton) {
		retryButton.addEventListener('click', retry);
	}

	function send(url, payload) {
		return fetch(url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': settings.nonce },
			body: JSON.stringify(payload || {})
		}).then(function (response) {
			return response.json().then(function (data) {
				if (!response.ok) {
					throw new Error(data && data.message ? data.message : response.statusText);
				}

				return data;
			});
		});
	}

	function start(selected, front, wantsImages) {
		progress.hidden = false;
		progress.classList.remove('familypedia-gedcom-progress--failed');
		working(true);
		say(l10n.starting, 0);

		send(settings.endpoint, { token: settings.token, selected: selected, front_page_roots: front, download_images: wantsImages })
			.then(function (started) {
				lastRun = started.run;
				return step(started.run);
			})
			.catch(failed);
	}

	/**
	 * Picks a run back up where it left off. A batch's own request either
	 * finished — its cursor moved on — or never reached the server at all,
	 * so asking for the same run again is always the right next step; it is
	 * never asked to redo a batch it already carried out.
	 */
	function retry() {
		if (!lastRun) {
			return;
		}

		if (retryButton) {
			retryButton.hidden = true;
		}
		progress.classList.remove('familypedia-gedcom-progress--failed');
		working(true);

		step(lastRun).catch(failed);
	}

	function step(run) {
		return send(settings.endpoint + '/' + run).then(function (state) {
			if (state.done) {
				say(state.message, 100);
				window.location = state.redirect;
				return;
			}

			// The server's stage names ('people', 'families', 'images') are
			// exactly the l10n keys their progress text lives under.
			say(
				(l10n[state.stage] || l10n.people)
					.replace('%1$s', state.position)
					.replace('%2$s', state.total),
				state.total ? Math.round((state.position / state.total) * 100) : 0
			);

			return step(run);
		});
	}

	function say(message, percent) {
		text.textContent = message;
		bar.value = percent;
	}

	function failed(error) {
		say(l10n.failed.replace('%s', error.message), 0);
		progress.classList.add('familypedia-gedcom-progress--failed');
		working(false);
		// A run that never got as far as a run id has nothing to retry —
		// starting over is what the still-enabled submit buttons are for.
		if (retryButton) {
			retryButton.hidden = !lastRun;
		}
		// Whoever was ticked when it stopped is still ticked, and the buttons
		// say so again rather than coming back as they were before the run.
		refresh();
	}

	/**
	 * While a run is going, the buttons that would start a second one are out of
	 * reach: the file is walked by cursor, and two walks would import it twice.
	 */
	function working(busy) {
		Array.prototype.slice.call(form.querySelectorAll('button, input')).forEach(function (control) {
			control.disabled = busy;
		});
	}

	renderTree();
	if (frontRoots) {
		// The page comes with a branch already suggested, which is only now
		// matched to the line it was drawn on.
		chooseFronts(frontRoots.value);
	}
	refresh();
	// Swapped only once the tree is standing, so the page is never left
	// without a way to pick people.
	treeBox.hidden = false;
	if (table) {
		table.hidden = true;
	}
}());
