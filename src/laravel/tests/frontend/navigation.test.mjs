import test from 'node:test';
import assert from 'node:assert/strict';
import {activeLink, navigationSection, secondaryLinks, primaryLinks} from '../../resources/js/navigation.mjs';

test('study routes keep Study active including resumed work', () => {
    for (const url of ['/', '/practice?mode=exam', '/fresh-catch', '/flashcards?decks=1', '/flashcards/decks/42', '/attempts/8', '/memory-checks/2', '/exams']) assert.equal(navigationSection(url), '/');
});
test('library filters preserve the Library section', () => assert.equal(navigationSection('/library?subject=Pediatrics&view=bookmarks'), '/library'));
test('progress tools share one primary section', () => {
    for (const url of ['/progress', '/progress/notebook', '/passport', '/leaderboard']) assert.equal(navigationSection(url), '/progress');
});
test('account and administration do not mislabel the current workspace', () => {
    for (const url of ['/profile', '/admin', '/admin/questions', '/settings', '/progressive']) assert.equal(navigationSection(url), null);
});
test('practice modes are distinct regardless of query ordering', () => {
    assert.equal(activeLink('/practice?count=10&mode=exam', '/practice?mode=exam'), true);
    assert.equal(activeLink('/practice?mode=tutor', '/practice?mode=exam'), false);
    assert.equal(activeLink('/practice?mode=fresh', '/fresh-catch'), true);
});
test('progress overview does not also activate on notebook', () => {
    assert.equal(activeLink('/progress/notebook', '/progress'), false);
    assert.equal(activeLink('/progress?subject=Pediatrics', '/progress'), true);
    assert.equal(activeLink('/flashcards/decks/42', '/flashcards'), true);
});
test('context links expose real tools without adding a third nav level', () => {
    assert.equal(primaryLinks.length, 3);
    assert.equal(secondaryLinks('/').length, 5);
    assert.equal(secondaryLinks('/progress/notebook').length, 4);
    assert.deepEqual(secondaryLinks('/library'), []);
    assert.deepEqual(secondaryLinks('/admin'), []);
});
