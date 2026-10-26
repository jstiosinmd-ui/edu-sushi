// Public navigation keeps each study tool grouped under the same workspace.
export const primaryLinks = [['Study', '/'], ['Library', '/library'], ['Progress', '/progress']];

const studyLinks = [['Daily catch', '/fresh-catch'], ['Flashcards', '/flashcards'], ['Tutor practice', '/practice?mode=tutor'], ['Timed exam', '/practice?mode=exam'], ['Exam builder', '/exams']];
const progressLinks = [['Overview', '/progress'], ['Mistakes and notes', '/progress/notebook'], ['Passport', '/passport'], ['Leaderboard', '/leaderboard']];

export function navigationSection(url) {
    const {pathname} = new URL(url, 'https://sushi.local');
    if (pathname === '/library') return '/library';
    if (['/progress', '/passport', '/leaderboard'].some(path => pathname === path || pathname.startsWith(path + '/'))) return '/progress';
    if (pathname === '/' || ['/fresh-catch', '/practice', '/flashcards', '/exams', '/attempts', '/memory-checks'].some(path => pathname === path || pathname.startsWith(path + '/'))) return '/';
    return null;
}

export function secondaryLinks(url) {
    const section = navigationSection(url);
    return section === '/' ? studyLinks : section === '/progress' ? progressLinks : [];
}

export function activeLink(url, target) {
    const current = new URL(url, 'https://sushi.local');
    const destination = new URL(target, 'https://sushi.local');
    if (target === '/fresh-catch') return current.pathname === '/fresh-catch' || (current.pathname === '/practice' && current.searchParams.get('mode') === 'fresh');
    if (target === '/flashcards') return current.pathname === '/flashcards' || current.pathname.startsWith('/flashcards/');
    if (current.pathname !== destination.pathname) return false;
    return [...destination.searchParams].every(([key, value]) => current.searchParams.get(key) === value);
}
