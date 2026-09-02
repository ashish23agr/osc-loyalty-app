import { vi } from 'vitest';

/**
 * Every export of `src/api/admin.js`, mocked.
 *
 * Declared once because a test file that lists only the endpoints it uses fails
 * confusingly the moment a screen it renders reaches for another one — vitest
 * reports a missing export from the mock rather than a missing stub. Each test
 * still sets the implementations it cares about.
 *
 * Used as:
 *   vi.mock('../src/api/admin.js', async () => (await import('./apiMock.js')).adminApiMock());
 */
export function adminApiMock() {
  return {
    getMe: vi.fn(),
    getOverview: vi.fn(),
    listMembers: vi.fn(),
    getMember: vi.fn(),
    getMemberLedger: vi.fn(),
    listProgrammeLedger: vi.fn(),
    adjustPoints: vi.fn(),
    listAudit: vi.fn(),
    getRules: vi.fn(),
    saveRules: vi.fn(),
  };
}
