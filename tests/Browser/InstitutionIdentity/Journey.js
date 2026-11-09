async page => {
    // Rendered by Run.ps1 into the private artifact directory; never commit rendered credentials.
    const fixture = /* PRIVATE_FIXTURE */ null;
    const phase = /* JOURNEY_PHASE */ null;
    const checks = [];
    const viewports = [];
    const check = (name, value) => { if (!value) throw new Error('Journey assertion failed: ' + name); checks.push(name); };
    const accountUrl = record => fixture.base + '/institution/' + record.organization + '/account';
    const login = async (target, record, password, success = true) => {
        await target.goto(fixture.base + '/institution/login');
        const form = target.getByRole('group', { name: 'تسجيل الدخول', exact: true });
        await form.getByRole('textbox', { name: 'معرّف المؤسسة', exact: true }).fill(record.organization);
        await form.getByRole('textbox', { name: 'البريد المسجل', exact: true }).fill(record.email);
        await form.getByRole('textbox', { name: 'كلمة المرور', exact: true }).fill(password);
        await form.getByRole('button', { name: 'تسجيل الدخول', exact: true }).click();
        if (success) {
            await target.waitForURL(accountUrl(record));
            await target.getByRole('heading', { name: 'الحساب المؤسسي', exact: true }).waitFor();
            check('correct account context', (await target.locator('main').innerText()).includes(record.name));
            check('login confers no workspace grant', await target.getByRole('link', { name: 'فتح مساحة المؤسسة', exact: true }).count() === 0);
        } else {
            await target.getByRole('alert').waitFor();
            check('generic invalid credential response', (await target.getByRole('alert').innerText()).includes('تعذر تسجيل الدخول'));
        }
    };
    const a = fixture.accounts[0], b = fixture.accounts[1];
    if (phase === 'before-reset') {
        const browser = page.context().browser();
        const bContext = await browser.newContext();
        const shadowContext = await browser.newContext();
        const bPage = await bContext.newPage(), shadow = await shadowContext.newPage();
        page.__identity53 = { bContext, shadowContext, bPage, shadow };
        await login(page, a, a.password);
        await page.reload();
        check('account survives refresh', page.url() === accountUrl(a));
        await page.screenshot({ path: fixture.artifacts + '/account-a.png', fullPage: true });
        await login(bPage, b, a.password, false);
        await login(bPage, b, b.password);
        await login(shadow, a, a.password);
        await page.goto(accountUrl(b));
        await page.waitForURL(fixture.base + '/institution/login');
        check('foreign institutional context rejected', !(await page.locator('main').innerText()).includes(b.name));
        await bPage.reload();
        check('other browser remains independent', bPage.url() === accountUrl(b));
        await login(page, a, a.password);
        const workspace = await page.request.get(fixture.base + '/institution/workspace', { maxRedirects: 0 });
        check('actual workspace default-deny', workspace.status() === 403);
        const csrf = await page.request.post(fixture.base + '/institution/login', {
            headers: { 'Sec-Fetch-Site': 'cross-site', Origin: 'https://example.invalid' },
            form: { organization: a.organization, email: a.email, password: 'invalid' }
        });
        check('CSRF remains active', csrf.status() === 419);
        const preview = await page.request.get(fixture.base + '/admin/ui-preview');
        check('no mockup route', preview.status() === 404);
        await page.getByRole('button', { name: 'تسجيل الخروج', exact: true }).click();
        await page.waitForURL(fixture.base + '/institution/login');
        await bPage.reload();
        check('logout does not clear another browser', bPage.url() === accountUrl(b));
        await login(page, a, a.password);
        await page.goto(fixture.base + '/institution/login');
        const recovery = page.getByRole('group', { name: 'استعادة الوصول', exact: true });
        await recovery.getByRole('textbox', { name: 'معرّف المؤسسة', exact: true }).fill(a.organization);
        await recovery.getByRole('textbox', { name: 'البريد المسجل', exact: true }).fill(a.email);
        await recovery.getByRole('button', { name: 'طلب تعليمات الاستعادة', exact: true }).click();
        await page.getByRole('status').waitFor();
        check('generic recovery receipt', (await page.getByRole('status').innerText()).includes('إذا كانت البيانات مطابقة'));
    } else if (phase === 'reset') {
        const { bPage, shadow } = page.__identity53;
        const invalid = await page.request.get(fixture.resetUrl.replace(/signature=[^&]+/, 'signature=invalid'));
        check('tampered signature rejected', invalid.status() === 403);
        const expired = await page.request.get(fixture.expiredUrl);
        check('expired signature rejected', expired.status() === 403);
        const foreign = await page.request.get(fixture.resetUrl.replace('/' + a.organization + '/', '/' + b.organization + '/'));
        check('cross-institution reset link rejected', foreign.status() === 403);
        await page.goto(fixture.resetUrl);
        await page.getByRole('textbox', { name: 'كلمة المرور الجديدة', exact: true }).fill(fixture.newPassword);
        await page.getByRole('textbox', { name: 'تأكيد كلمة المرور', exact: true }).fill('different-confirmation');
        await page.getByRole('button', { name: 'حفظ كلمة المرور', exact: true }).click();
        await page.getByRole('alert').waitFor();
        check('confirmation validation displayed', (await page.getByRole('alert').innerText()).includes('تأكيد كلمة المرور'));
        await page.getByRole('textbox', { name: 'كلمة المرور الجديدة', exact: true }).fill(fixture.newPassword);
        await page.getByRole('textbox', { name: 'تأكيد كلمة المرور', exact: true }).fill(fixture.newPassword);
        await page.getByRole('button', { name: 'حفظ كلمة المرور', exact: true }).click();
        await page.waitForURL(fixture.base + '/institution/login');
        check('reset success visible', (await page.getByRole('status').innerText()).includes('تم تغيير كلمة المرور'));
        await shadow.goto(accountUrl(a));
        await shadow.waitForURL(fixture.base + '/institution/login');
        check('pre-reset browser session invalidated', true);
        await bPage.reload();
        check('other institution session survives reset', bPage.url() === accountUrl(b));
        await login(page, a, a.password, false);
        await login(page, a, fixture.newPassword);
        await login(bPage, b, fixture.newPassword, false);
        await login(bPage, b, b.password);
        await page.goto(fixture.resetUrl);
        await page.getByRole('textbox', { name: 'كلمة المرور الجديدة', exact: true }).fill(fixture.newPassword + 'replay');
        await page.getByRole('textbox', { name: 'تأكيد كلمة المرور', exact: true }).fill(fixture.newPassword + 'replay');
        await page.getByRole('button', { name: 'حفظ كلمة المرور', exact: true }).click();
        await page.getByRole('alert').waitFor();
        check('consumed reset token rejected', (await page.getByRole('alert').innerText()).includes('رابط الاستعادة غير صالح'));
        await page.goto(accountUrl(a));
        check('new session remains valid after denied replay', page.url() === accountUrl(a));
    } else if (phase === 'expired-sessions') {
        const { bPage, bContext, shadowContext } = page.__identity53;
        await page.goto(accountUrl(a));
        await page.waitForURL(fixture.base + '/institution/login');
        await bPage.goto(accountUrl(b));
        await bPage.waitForURL(fixture.base + '/institution/login');
        check('owned file-session expiry enforced', true);
        for (const width of [375, 768, 1280]) {
            await page.setViewportSize({ width, height: 900 });
            await page.evaluate(() => document.fonts.ready);
            check('RTL ' + width, await page.evaluate(() => document.documentElement.dir === 'rtl'));
            check('no overflow ' + width, await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
            const geometry = await page.evaluate(() => {
                const rect = document.querySelector('main').getBoundingClientRect();
                return { innerWidth, clientWidth: document.documentElement.clientWidth, left: rect.left, right: rect.right };
            });
            check('content inside viewport ' + width, geometry.left >= 0 && geometry.right <= geometry.innerWidth);
            viewports.push({ width, ...geometry });
            // Avoid a full-page capture cropping RTL content when its left scrollbar is excluded.
            // Test the real 900px viewport above; resize only the evidence capture, without changing DOM/CSS.
            await page.setViewportSize({ width, height: 1600 });
            const height = await page.locator('main').evaluate(element => Math.ceil(element.getBoundingClientRect().height) + 16);
            await page.setViewportSize({ width, height: Math.max(900, height) });
            await page.screenshot({ path: fixture.artifacts + '/entry-' + width + '.png', fullPage: false });
        }
        check('native Filament components', await page.locator('.fi-btn').count() > 0);
        await bContext.close();
        await shadowContext.close();
        delete page.__identity53;
    } else {
        throw new Error('Unknown journey phase.');
    }
    return { phase, passed: true, checks, viewports };
}
