// Existing Playwright CLI; credentials are injected only in a private temporary file.
async (page) => {
    const f = /* PRIVATE_FIXTURE */ null;
    const phase = /* JOURNEY_PHASE */ null;
    const checks = [];
    const check = (ok, name) => { if (!ok) throw new Error(name); checks.push(name); };
    const errors = [];
    const visible = async (locator, name) => {
        try { await locator.waitFor({ state: 'visible', timeout: 60000 }); }
        catch (error) {
            const p = locator.page();
            await p.screenshot({ path: f.artifacts + '/failure.png', fullPage: true });
            throw new Error(name + '; visible dialogs: ' + JSON.stringify(await p.getByRole('dialog').allTextContents())
                + '; buttons: ' + JSON.stringify(await p.getByRole('button').allTextContents()));
        }
        check(true, name);
    };
    const button = (p, name) => p.getByRole('button', { name, exact: true });
    const go = (p, path) => p.goto(f.base + path, { waitUntil: 'domcontentloaded', timeout: 60000 });
    const bank = '/institution/document-workspace/documents';
    const login = async (name) => {
        const context = await page.context().browser().newContext({ viewport: { width: 1280, height: 900 }, reducedMotion: 'reduce' });
        const p = await context.newPage();
        p.on('pageerror', error => errors.push(error.message));
        await p.bringToFront();
        await go(p, '/institution/login');
        const group = p.getByRole('group', { name: 'تسجيل الدخول', exact: true });
        const account = f.accounts[name];
        await group.getByRole('textbox', { name: 'معرّف المؤسسة', exact: true }).fill(account.organization);
        await group.getByRole('textbox', { name: 'البريد المسجل', exact: true }).fill(account.email);
        await group.getByRole('textbox', { name: 'كلمة المرور', exact: true }).fill(account.password);
        await button(group, 'تسجيل الدخول').click({ noWaitAfter: true });
        await visible(p.getByRole('heading', { name: 'الحساب المؤسسي', exact: true }), name + ' actual institutional login');
        check(await p.getByRole('link', { name: 'فتح مساحة المؤسسة', exact: true }).count() === 0, name + ' has no J03 Approach admission');
        return p;
    };
    const layout = async (p, name) => {
        await p.bringToFront();
        await p.waitForLoadState('load', { timeout: 60000 });
        await p.evaluate(() => document.fonts.ready);
        check(await p.locator('html').getAttribute('dir') === 'rtl', name + ' Arabic RTL');
        check(await p.locator('.fi-btn').count() > 0, name + ' native Filament buttons');
        for (const width of [1280, 768, 375]) {
            await p.setViewportSize({ width, height: 900 });
            await p.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
            check(await p.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), name + ' no page overflow ' + width);
            await p.screenshot({ path: f.artifacts + '/' + name + '-' + width + '.png', fullPage: true });
        }
        await p.setViewportSize({ width: 1280, height: 900 });
    };
    if (phase === 'workflow') {
        const editor = await login('editor');
        check((await editor.context().request.get(f.base + '/institution/workspace')).status() === 403, 'Approach workspace remains forbidden');
        await go(editor, bank);
        await visible(editor.getByRole('heading', { name: 'بنك الوثائق', exact: true, level: 1 }), 'independent native Document bank admitted');
        check(!editor.url().includes('/admin/ui-preview'), 'actual native panel, not preview');
        check(!(await editor.locator('body').innerText()).includes('وثيقة غير مسندة'), 'unassigned document excluded');
        await button(editor, 'إنشاء وثيقة اختبارية').click();
        let dialog = editor.getByRole('dialog');
        await visible(dialog.getByRole('textbox', { name: /^عنوان الوثيقة/ }), 'native document create modal');
        await dialog.getByRole('textbox', { name: /^عنوان الوثيقة/ }).fill('وثيقة الرحلة الفعلية');
        await dialog.getByRole('textbox', { name: /^الوصف/ }).fill('وصف الوثيقة من المتصفح');
        await button(dialog, 'إنشاء الوثيقة').click();
        await dialog.getByRole('textbox', { name: /^عنوان الوثيقة/ }).waitFor({ state: 'hidden', timeout: 60000 });
        const row = editor.getByRole('link').filter({ hasText: 'وثيقة الرحلة الفعلية' }).first();
        await visible(row, 'browser-created document in assigned SQL listing');
        const resource = (await row.getAttribute('href')).split('/').pop();
        check(/^[0-9A-HJKMNP-TV-Z]{26}$/i.test(resource), 'only public document ULID exposed');
        await layout(editor, 'document-bank');
        await go(editor, bank + '/' + resource);
        await visible(button(editor, 'تعديل البيانات'), 'explicit document metadata grant');
        await button(editor, 'تعديل البيانات').click();
        dialog = editor.getByRole('dialog');
        await visible(dialog.getByRole('textbox', { name: /^عنوان الوثيقة/ }), 'native metadata modal');
        await dialog.getByRole('textbox', { name: /^عنوان الوثيقة/ }).fill('وثيقة الرحلة المحدثة');
        await dialog.getByRole('textbox', { name: /^الوصف/ }).fill('وصف محدث محفوظ من المتصفح');
        await button(dialog, 'حفظ البيانات').click();
        await dialog.getByRole('textbox', { name: /^عنوان الوثيقة/ }).waitFor({ state: 'hidden', timeout: 60000 });
        await visible(editor.getByText('وثيقة الرحلة المحدثة', { exact: true }), 'updated metadata visible immediately');
        await button(editor, 'إنشاء إصدار اختباري').click();
        dialog = editor.getByRole('alertdialog');
        await visible(button(dialog, 'إنشاء الإصدار'), 'native synthetic version confirmation');
        await button(dialog, 'إنشاء الإصدار').click();
        await button(dialog, 'إنشاء الإصدار').waitFor({ state: 'hidden', timeout: 60000 });
        await go(editor, bank + '/' + resource);
        await visible(editor.getByText('وثيقة الرحلة المحدثة', { exact: true }), 'metadata survives reload');
        check(await editor.locator('.fi-ta-record').count() === 2, 'two persisted immutable content versions');
        await layout(editor, 'document-versions');
        await editor.getByLabel('نوع السجل', { exact: true }).selectOption('metadata');
        await visible(editor.getByText('البيانات الأصلية المحفوظة', { exact: true }), 'original metadata history available');
        await visible(editor.getByText('وثيقة الرحلة الفعلية', { exact: true }), 'original title retained');
        await layout(editor, 'document-metadata-history');
        const reader = await login('reader');
        await go(reader, bank);
        await visible(reader.getByRole('link').filter({ hasText: 'وثيقة الرحلة المحدثة' }).first(), 'independent read-only document listing');
        check(await button(reader, 'إنشاء وثيقة اختبارية').count() === 0, 'reader cannot create');
        await go(reader, bank + '/' + resource);
        check(await button(reader, 'تعديل البيانات').count() === 0, 'reader cannot edit metadata');
        check(await button(reader, 'إنشاء إصدار اختباري').count() === 0, 'reader cannot append');
        await layout(reader, 'document-reader');
        for (const id of [f.hidden, f.foreign]) {
            const response = await reader.context().request.get(f.base + bank + '/' + id);
            check([403, 404].includes(response.status()), 'unassigned/cross-tenant document denied');
        }
        for (const name of ['issuer', 'unassigned', 'foreign']) {
            const denied = await login(name);
            check((await denied.context().request.get(f.base + bank)).status() === 403, name + ' has no Document admission');
            await denied.context().close();
        }
        for (const path of ['/storage/anything', '/institution/document-workspace/documents/' + resource + '/download', '/livewire/upload-file']) {
            check([403, 404, 405].includes((await reader.context().request.get(f.base + path)).status()), 'no file intake/download/serving ' + path);
        }
        const csrf = await editor.context().request.post(f.base + '/institution/' + f.accounts.editor.organization + '/logout', { form: {}, headers: { Origin: 'https://invalid.example', 'Sec-Fetch-Site': 'cross-site' } });
        check(csrf.status() === 419, 'actual native HTTP CSRF protection');
        check(errors.length === 0, 'no browser JavaScript exceptions');
        page.__documents = { editor, reader, resource };
        return { passed: true, resource, checks };
    }
    const { editor, reader, resource } = page.__documents;
    if (phase === 'revoked') {
        check((await reader.context().request.get(f.base + bank + '/' + resource)).status() === 403, 'existing reader session denied after actual grant revocation');
        await go(reader, bank);
        check(await reader.getByRole('link').filter({ hasText: 'وثيقة الرحلة المحدثة' }).count() === 0, 'revoked document absent from SQL listing');
        check((await editor.context().request.get(f.base + bank + '/' + resource)).status() === 200, 'editor remains independently authorized');
    } else if (phase === 'expired') {
        check((await editor.context().request.get(f.base + bank)).status() === 403, 'existing editor session denied after disclosed fixture expiry');
        for (const [name, p] of [['editor', editor], ['reader', reader]]) {
            await go(p, '/institution/' + f.accounts[name].organization + '/account');
            await button(p, 'تسجيل الخروج').click({ noWaitAfter: true });
            await visible(p.getByRole('heading', { name: 'دخول الحساب المؤسسي', exact: true }), name + ' actual original logout');
            check((await p.context().request.get(f.base + bank, { maxRedirects: 0 })).status() === 302, name + ' guest cannot enter document panel');
            await p.context().close();
        }
    } else throw new Error('Unknown explicit document journey phase');
    return { passed: true, checks };
}
