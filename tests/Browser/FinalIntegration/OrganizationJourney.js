// Existing installed Playwright library; no new application dependency.
// CLI run-code echoes its source. Read credentials locally instead of injecting
// them into echoed code, command arguments, snapshots, traces or result logs.
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
const require = createRequire(import.meta.url);
const __dirname = path.dirname(fileURLToPath(import.meta.url));
const { chromium } = require(process.env.QEP_EXISTING_PLAYWRIGHT_MODULE);

async function main() {
    const root = path.resolve(__dirname, '../../..');
    const run = process.argv[2];
    const phase = process.argv[3] || 'inspect';
    if (! /^browser-\d{2}$/.test(run || '') || ! ['inspect', 'journey'].includes(phase)) throw new Error('Explicit owned browser run required.');
    const privateRoot = path.join(root, '.local-tools/reports/issue62/review-maturity', run);
    if (fs.realpathSync(privateRoot).toLowerCase() !== privateRoot.toLowerCase()) throw new Error('Noncanonical private browser root.');
    const state = JSON.parse(fs.readFileSync(path.join(privateRoot, 'connection.json'), 'utf8'));
    const fixture = JSON.parse(fs.readFileSync(path.join(privateRoot, 'fixture.json'), 'utf8'));
    if (state.namespace !== 'qep-issue62-review-maturity-browser' || state.run_name !== run || state.http_host !== '127.0.0.6'
        || state.database !== 'quality_platform_ci' || fixture.owner !== state.fixture_id
        || fixture.panel_proof.workflow.server_uuid !== state.server_uuid || fixture.panel_proof.organization_units?.scenario !== 'UNIT_LOCAL_001') throw new Error('Unowned integration fixture.');
    for (const [file, expected] of Object.entries(state.source_hashes)) {
        if (crypto.createHash('sha256').update(fs.readFileSync(path.join(root, file))).digest('hex') !== expected) throw new Error('Reviewed fixture source changed: ' + file);
    }
    const base = `http://${state.http_host}:${state.http_port}`;
    const artifacts = path.join(privateRoot, 'output/playwright');
    fs.mkdirSync(artifacts, { recursive: true });
    const checks = [];
    const contexts = [];
    const check = (value, description) => { if (!value) throw new Error(description); checks.push(description); };
    let browser;
    try {
        const options = { headless: true, args: ['--renderer-process-limit=2'] };
        if (! fs.existsSync(chromium.executablePath())) options.channel = 'chrome';
        browser = await chromium.launch(options);
        const context = await browser.newContext({ viewport: { width: 1280, height: 900 }, reducedMotion: 'reduce' });
        contexts.push(context);
        const page = await context.newPage();
        const response = await page.goto(base + '/institution/login');
        check(response.status() === 200 && response.headers()['x-qep-review-maturity-fixture'] === state.fixture_id, 'Owned loopback response identity');
        const account = fixture.accounts.author;
        const group = page.getByRole('group', { name: 'تسجيل الدخول', exact: true });
        await group.getByRole('textbox', { name: 'معرّف المؤسسة', exact: true }).fill(account.organization);
        await group.getByRole('textbox', { name: 'البريد المسجل', exact: true }).fill(account.email);
        await group.getByRole('textbox', { name: 'كلمة المرور', exact: true }).fill(account.password);
        await group.getByRole('button', { name: 'تسجيل الدخول', exact: true }).click();
        await page.getByRole('heading', { name: 'الحساب المؤسسي', exact: true }).waitFor();
        check(true, 'Actual institution login');
        await page.getByRole('link', { name: 'فتح العرض المتكامل', exact: true }).click();
        await page.getByRole('heading', { name: 'المراجعة والنضج', exact: true, level: 1 }).waitFor();
        check(true, 'Policy-gated real account-to-workspace link');
        await page.getByRole('link', { name: 'بنك المنهجيات', exact: true }).click();
        await page.getByRole('heading', { name: 'بنك المنهجيات', exact: true, level: 1 }).waitFor();
        await page.locator('a[href$="/approaches/' + fixture.approach + '"]').first().click();
        await page.getByRole('heading', { name: 'مساحة المنهجية', exact: true, level: 1 }).waitFor();
        check(true, 'Existing native assigned Approach bank and detail in the same panel');
        check(await page.getByRole('button', { name: 'تعديل البيانات التعريفية', exact: true }).count() === 0,
            'Contributor receives no Quality structural-metadata grant');
        await page.screenshot({ path: path.join(artifacts, 'assigned-approach-workspace.png'), fullPage: true });
        await page.getByRole('link', { name: 'بنك الوثائق', exact: true }).click();
        await page.getByRole('heading', { name: 'بنك الوثائق', exact: true, level: 1 }).waitFor();
        await page.locator('a[href$="/documents/' + fixture.document + '"]').first().click();
        await page.getByRole('heading', { name: 'مساحة الوثيقة', exact: true, level: 1 }).waitFor();
        check(true, 'Existing native authorized Document and immutable version view');
        check(await page.getByRole('button', { name: /تنزيل|تحميل ملف|إتاحة تقنية/ }).count() === 0,
            'Synthetic workspace exposes no real-file operation');
        await page.screenshot({ path: path.join(artifacts, 'assigned-document-workspace.png'), fullPage: true });
        await page.getByRole('link', { name: 'المؤسسة والوحدات', exact: true }).click();
        await page.getByRole('heading', { name: 'المؤسسة والوحدات', exact: true, level: 1 }).waitFor();
        check(!page.url().includes('ui-preview'), 'Actual native organization page');
        for (const width of [1280, 768, 375]) {
            await page.setViewportSize({ width, height: 900 });
            await page.evaluate(() => document.fonts.ready);
            check(await page.locator('html').getAttribute('dir') === 'rtl', 'Arabic RTL ' + width);
            check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'No viewport overflow ' + width);
            await page.screenshot({ path: path.join(artifacts, 'organization-' + width + '.png'), fullPage: true });
        }
        const mobileActions = page.getByRole('button', { name: 'إجراءات الوحدة', exact: true }).first();
        const mobileBox = await mobileActions.boundingBox();
        check(mobileBox && mobileBox.width > 0 && mobileBox.x >= 0 && mobileBox.x + mobileBox.width <= 376,
            'Native management control fully visible at 375');
        await mobileActions.click();
        const mobileMenu = page.locator('.fi-dropdown-panel:visible').last();
        const renameItem = mobileMenu.getByText('تغيير الاسم', { exact: true });
        await renameItem.waitFor();
        const menuBox = await mobileMenu.boundingBox();
        check(menuBox && menuBox.x >= 0 && menuBox.x + menuBox.width <= 376,
            'Native mobile action menu remains within viewport');
        await page.screenshot({ path: path.join(artifacts, 'organization-mobile-actions.png'), fullPage: true });
        await page.keyboard.press('Escape');
        await page.setViewportSize({ width: 1280, height: 900 });
        check(await page.locator('.fi-ta').count() > 0, 'Native Filament table');
        await page.getByRole('button', { name: 'إنشاء وحدة', exact: true }).click();
        const dialog = page.locator('[role="dialog"]').last();
        await dialog.getByRole('textbox', { name: /^اسم الوحدة/ }).waitFor();
        const form = await dialog.locator('input,select,button,[role=combobox]').evaluateAll(nodes => nodes.map(n => ({ tag: n.tagName, type: n.getAttribute('type'), role: n.getAttribute('role'), name: n.getAttribute('name'), ariaLabel: n.getAttribute('aria-label'), text: n.tagName === 'BUTTON' ? n.textContent.trim().slice(0, 80) : null })));
        await page.screenshot({ path: path.join(artifacts, 'native-create-modal.png'), fullPage: true });
        if (phase === 'journey') {
            const button = (scope, name) => scope.getByRole('button', { name, exact: true });
            const finalName = 'وحدة العرض المتكامل المحدّثة';
            const initialName = 'وحدة العرض المتكامل';
            const parentName = 'وحدة اختبار المنهجيات';
            const row = (name) => page.locator('.fi-ta-record, .fi-ta-row').filter({ has: page.locator('.fi-ta-text').first().getByText(name, { exact: true }) });
            const openRowAction = async (target, name) => {
                const direct = target.getByRole('button', { name, exact: true });
                if (await direct.isVisible()) return direct.click();
                await target.getByRole('button', { name: 'إجراءات الوحدة', exact: true }).click();
                const item = page.getByRole('menuitem', { name, exact: true });
                await item.waitFor();
                return item.click();
            };
            const save = async (label) => {
                await button(dialog, label).click();
                await button(dialog, label).waitFor({ state: 'hidden', timeout: 60000 });
            };
            const chooseParent = async (name) => {
                const search = dialog.getByRole('combobox', { name: 'الوحدة الأعلى: بحث', exact: true });
                if (! await search.isVisible()) await dialog.locator('button.fi-select-input-btn').click();
                await search.fill(name);
                await page.getByRole('option', { name, exact: true }).click();
            };
            await dialog.getByRole('textbox', { name: /^اسم الوحدة/ }).fill(initialName);
            await save('حفظ الوحدة');
            await row(initialName).waitFor();
            check(true, 'Real native create persisted and displayed');
            await openRowAction(row(initialName), 'تغيير الاسم');
            await dialog.getByRole('textbox', { name: /^اسم الوحدة/ }).fill(finalName);
            await save('حفظ الاسم');
            await row(finalName).waitFor();
            check(true, 'Real native rename persisted and displayed');
            await openRowAction(row(finalName), 'نقل الوحدة');
            await chooseParent(parentName);
            await save('حفظ النقل');
            check((await row(finalName).innerText()).includes(parentName), 'Real native move reflected in current parent');
            await page.reload();
            await row(finalName).waitFor();
            check((await row(finalName).innerText()).includes(parentName), 'Independent HTTP refresh preserves name and parent');
            await openRowAction(row(parentName), 'نقل الوحدة');
            await chooseParent(finalName);
            await button(dialog, 'حفظ النقل').click();
            await dialog.getByText('لا يمكن نقل الوحدة إلى نفسها أو إلى أحد فروعها.', { exact: true }).waitFor();
            check(true, 'Native cycle attempt rejected with Arabic validation');
            await button(dialog, 'إلغاء').click();
            await page.screenshot({ path: path.join(artifacts, 'organization-after-mutations.png'), fullPage: true });
            const loginOther = async (name) => {
                const c = await browser.newContext({ viewport: { width: 1280, height: 900 }, reducedMotion: 'reduce' });
                const p = await c.newPage();
                const a = fixture.accounts[name];
                await p.goto(base + '/institution/login');
                const g = p.getByRole('group', { name: 'تسجيل الدخول', exact: true });
                await g.getByRole('textbox', { name: 'معرّف المؤسسة', exact: true }).fill(a.organization);
                await g.getByRole('textbox', { name: 'البريد المسجل', exact: true }).fill(a.email);
                await g.getByRole('textbox', { name: 'كلمة المرور', exact: true }).fill(a.password);
                await button(g, 'تسجيل الدخول').click();
                await p.getByRole('heading', { name: 'الحساب المؤسسي', exact: true }).waitFor();
                return { context: c, page: p };
            };
            for (const name of ['reviewer', 'foreign']) {
                const other = await loginOther(name);
                try {
                    const denied = await other.page.goto(base + '/institution/mvp-workspace/organization');
                    check(denied.status() === 403, name + ' denied by real admission/authorization');
                    check(! (await other.page.locator('body').innerText()).includes(finalName), name + ' receives no unit content');
                } finally { await other.context.close(); }
            }
            const guest = await browser.newContext();
            try {
                const denied = await guest.request.post(base + '/institution/login', { form: { organization: '0'.repeat(26), email: 'denied@example.test', password: 'nonexistent-fixture-input' }, maxRedirects: 0 });
                check([419, 403].includes(denied.status()), 'Actual browser/server CSRF rejection');
            } finally { await guest.close(); }
            await page.goto(base + '/institution/' + account.organization + '/account');
            await button(page, 'تسجيل الخروج').click();
            await page.getByRole('heading', { name: 'دخول الحساب المؤسسي', exact: true }).waitFor();
            await page.goto(base + '/institution/mvp-workspace/organization');
            check(page.url().endsWith('/institution/login'), 'Actual logout denies native reentry');
            for (const c of contexts) await c.close();
            contexts.length = 0;
            const workflowPage = await browser.newPage();
            const source = fs.readFileSync(path.join(root, 'tests/Browser/ReviewMaturity/Journey.js'), 'utf8');
            if (!source.includes('/* PRIVATE_FIXTURE */ null') || !source.includes('/* JOURNEY_PHASE */ null')) throw new Error('Unexpected trusted workflow test source.');
            const workflowData = { base, accounts: fixture.accounts, approach: fixture.approach, stages: fixture.stages, foreign: fixture.foreign, artifacts: artifacts.split(path.sep).join('/') };
            // Compile only reviewed local test source. Private data is a closure
            // argument, never interpolated into echoed source or tool arguments.
            const executeWorkflow = new Function('fixtureData', 'journeyPhase', 'return (\n' + source.replace('/* PRIVATE_FIXTURE */ null', 'fixtureData').replace('/* JOURNEY_PHASE */ null', 'journeyPhase') + '\n)')(workflowData, 'workflow');
            const workflow = await executeWorkflow(workflowPage);
            check(workflow.passed === true, 'Integrated review/correction/five-stage/notification browser flow');
            fs.writeFileSync(path.join(privateRoot, 'workflow-browser-results.json'), JSON.stringify(workflow, null, 2));
            checks.push(...workflow.checks.map(c => 'workflow: ' + c));
        }

        const result = { passed: true, classification: 'TEST_ONLY', phase, checks, browser: browser.version(), modal: form };
        fs.writeFileSync(path.join(privateRoot, 'organization-inspection.json'), JSON.stringify(result, null, 2));
        console.log(JSON.stringify({ passed: result.passed, classification: result.classification, phase, checks: checks.length, browser: result.browser }));
    } catch (error) {
        let message = String(error.message || error);
        for (const account of Object.values(fixture.accounts)) {
            message = message.split(account.password).join('[private]').split(account.email).join('[private]');
        }
        fs.writeFileSync(path.join(privateRoot, 'organization-error.json'), JSON.stringify({ phase, message, checks }, null, 2));
        console.error('Owned browser organization phase failed; redacted private diagnostic retained.');
        process.exitCode = 1;
    } finally {
        for (const context of contexts) await context.close();
        if (browser) await browser.close();
    }
}
main().catch(() => { console.error('Browser preflight failed; no credentials logged.'); process.exitCode = 1; });
