// Existing installed Playwright CLI. Random credentials enter only an owner-private temporary file.
async (page) => {
    const f = /* PRIVATE_FIXTURE */ null;
    const phase = /* JOURNEY_PHASE */ null;
    const checks = [];
    const errors = [];
    const contexts = [];
    const check = (ok, name) => { if (!ok) throw new Error(name); checks.push(name); };
    const button = (p, name) => p.getByRole('button', { name, exact: true });
    const go = (p, path) => p.goto(f.base + path, { waitUntil: 'domcontentloaded', timeout: 60000 });
    const visible = async (locator, name) => {
        try { await locator.waitFor({ state: 'visible', timeout: 60000 }); }
        catch (error) {
            const p = locator.page();
            await p.screenshot({ path: f.artifacts + '/failure.png', fullPage: true });
            throw new Error(name + '; buttons: ' + JSON.stringify(await p.getByRole('button').allTextContents()));
        }
        check(true, name);
    };
    const login = async (name) => {
        const context = await page.context().browser().newContext({ viewport: { width: 1280, height: 900 }, reducedMotion: 'reduce' });
        contexts.push(context);
        const p = await context.newPage();
        p.on('pageerror', error => errors.push(error.message));
        await go(p, '/institution/login');
        const group = p.getByRole('group', { name: 'تسجيل الدخول', exact: true });
        const account = f.accounts[name];
        await group.getByRole('textbox', { name: 'معرّف المؤسسة', exact: true }).fill(account.organization);
        await group.getByRole('textbox', { name: 'البريد المسجل', exact: true }).fill(account.email);
        await group.getByRole('textbox', { name: 'كلمة المرور', exact: true }).fill(account.password);
        await button(group, 'تسجيل الدخول').click({ noWaitAfter: true });
        await visible(p.getByRole('heading', { name: 'الحساب المؤسسي', exact: true }), name + ' original institutional login');
        return p;
    };
    const layout = async (p, name) => {
        await p.bringToFront();
        await p.waitForLoadState('load', { timeout: 60000 });
        await p.evaluate(() => document.fonts.ready);
        check(await p.locator('html').getAttribute('dir') === 'rtl', name + ' Arabic RTL');
        check(await p.locator('.fi-ta, .fi-btn').count() > 0, name + ' real native Filament table/actions');
        for (const width of [1280, 768, 375]) {
            await p.setViewportSize({ width, height: 900 });
            await p.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
            check(await p.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), name + ' no page overflow ' + width);
            await p.screenshot({ path: f.artifacts + '/' + name + '-' + width + '.png', fullPage: true });
        }
        await p.setViewportSize({ width: 1280, height: 900 });
    };
    const content = '/institution/mvp-workspace/workflows/' + f.approach;
    const saveContent = async (p, text) => {
        await button(p, 'تحرير المحتوى').click();
        const dialog = p.getByRole('dialog');
        await visible(dialog.getByRole('textbox', { name: /^محتوى المنهجية/ }), 'native real content editor');
        await dialog.getByRole('textbox', { name: /^محتوى المنهجية/ }).fill(text);
        await button(dialog, 'حفظ المحتوى').click();
        await dialog.waitFor({ state: 'hidden', timeout: 60000 });
        await visible(p.getByText(text, { exact: true }), 'content persisted through real Action');
    };
    if (phase !== 'workflow') throw new Error('Unknown bounded browser phase');
    try {
        const author = await login('author');
        const reviewer = await login('reviewer');
        check(await reviewer.getByRole('link', { name: 'فتح مساحة المؤسسة', exact: true }).count() === 0, 'reviewer receives no implicit J03 panel access');
        await go(author, '/institution/mvp-workspace/workflows');
        await visible(author.getByRole('heading', { name: 'المراجعة والنضج', exact: true, level: 1 }), 'one actual native assigned workflow bank');
        check(!author.url().includes('/admin/ui-preview'), 'not UI preview');
        await layout(author, 'workflow-bank');
        await go(author, content);
        await saveContent(author, 'محتوى الرحلة الأول من المتصفح');
        check(await button(author, 'قرار المراجعة').count() === 0, 'author has no self-review control');
        await go(reviewer, content);
        check(await button(reviewer, 'تحرير المحتوى').count() === 0, 'independent reviewer cannot edit');
        await button(reviewer, 'قرار المراجعة').click();
        let dialog = reviewer.getByRole('dialog');
        await visible(dialog.getByRole('combobox', { name: /^القرار/ }), 'native independent decision modal');
        await dialog.getByRole('combobox', { name: /^القرار/ }).selectOption('changes_requested');
        await dialog.getByRole('textbox', { name: /^ملاحظات المراجعة/ }).fill('أضف وصف التطبيق قبل الاعتماد.');
        await button(dialog, 'حفظ القرار').click();
        await dialog.waitFor({ state: 'hidden', timeout: 60000 });
        await go(author, content);
        await visible(author.getByText('تصحيح مطلوب', { exact: true }), 'persisted correction visible to author');
        await saveContent(author, 'محتوى الرحلة المصحح مع وصف التطبيق من المتصفح');
        await go(reviewer, content);
        await button(reviewer, 'قرار المراجعة').click();
        dialog = reviewer.getByRole('dialog');
        await dialog.getByRole('combobox', { name: /^القرار/ }).selectOption('approved');
        await dialog.getByRole('textbox', { name: /^ملاحظات المراجعة/ }).fill('اعتماد مستقل للإصدار المصحح.');
        await button(dialog, 'حفظ القرار').click();
        await dialog.waitFor({ state: 'hidden', timeout: 60000 });
        await go(reviewer, content);
        await visible(reviewer.getByText('معتمد', { exact: true }), 'approval survives reload');
        await visible(reviewer.getByText('تصحيح مطلوب', { exact: true }), 'historical correction survives approval');
        await layout(reviewer, 'content-history');
        for (let i = 0; i < f.stages.length; i++) {
            const stagePath = content + '/maturity/' + f.stages[i];
            await go(reviewer, stagePath);
            await button(reviewer, 'إعدادات المرحلة').click();
            dialog = reviewer.getByRole('dialog');
            await visible(button(dialog, 'إضافة سؤال'), 'real editable question configuration ' + (i + 1));
            await button(dialog, 'إضافة سؤال').click();
            await dialog.getByRole('textbox', { name: /^نص السؤال/ }).fill('سؤال المرحلة ' + (i + 1) + ' من رحلة المالك');
            await dialog.getByRole('textbox', { name: /^التعليمات/ }).fill('أجب واربط الشواهد الاصطناعية المحددة.');
            await dialog.getByRole('textbox', { name: /^وصف الشاهد المتوقع/ }).nth(0).fill('شاهد أول للمرحلة ' + (i + 1));
            await button(dialog, 'إضافة شاهد متوقع').click();
            await dialog.getByRole('textbox', { name: /^وصف الشاهد المتوقع/ }).nth(1).fill('شاهد ثان للمرحلة ' + (i + 1));
            for (let b = 0; b < 2; b++) {
                await button(dialog, 'إضافة حالة تقييم').click();
                await dialog.getByRole('textbox', { name: /^اسم الحالة/ }).nth(b).fill(b === 0 ? 'بداية تجريبية' : 'تقدم تجريبي');
                await dialog.getByRole('textbox', { name: /^وصف الحالة/ }).nth(b).fill('تصنيف يدوي في العرض المعزول فقط');
                await dialog.getByRole('spinbutton', { name: /^الحد الأدنى/ }).nth(b).fill(b === 0 ? '0' : '31');
                await dialog.getByRole('spinbutton', { name: /^الحد الأعلى/ }).nth(b).fill(b === 0 ? '30' : '100');
            }
            await button(dialog, 'حفظ الإعدادات').click();
            await dialog.waitFor({ state: 'hidden', timeout: 60000 });
            await go(author, stagePath);
            await button(author, 'تحرير إجابات المرحلة').click();
            dialog = author.getByRole('dialog');
            await visible(dialog.getByRole('textbox', { name: /^سؤال المرحلة/ }), 'actual configured text answer ' + (i + 1));
            await dialog.getByRole('textbox', { name: /^سؤال المرحلة/ }).fill('إجابة المرحلة ' + (i + 1) + ' محفوظة من المتصفح مع الشواهد.');
            for (const label of ['شاهد أول للمرحلة ', 'شاهد ثان للمرحلة ']) {
                const selector = dialog.getByRole('combobox', { name: label + (i + 1), exact: true });
                const options = await selector.locator('option').evaluateAll(nodes => nodes.map(n => n.value).filter(Boolean));
                check(options.length === 1, 'only the actual authorized owned synthetic version offered ' + (i + 1));
                await selector.selectOption(options[0]);
            }
            await button(dialog, 'حفظ مسودة المرحلة').click();
            await dialog.waitFor({ state: 'hidden', timeout: 60000 });
            await go(reviewer, stagePath);
            await visible(reviewer.getByText('إجابة المرحلة ' + (i + 1) + ' محفوظة من المتصفح مع الشواهد.', { exact: true }), 'actual answer readback ' + (i + 1));
            await button(reviewer, 'مراجعة وتقييم المرحلة').click();
            dialog = reviewer.getByRole('dialog');
            await dialog.getByRole('combobox', { name: /^القرار/ }).selectOption('approved');
            await dialog.getByRole('combobox', { name: /^التصنيف اليدوي/ }).selectOption('0');
            await dialog.getByRole('textbox', { name: /^تعليل القرار/ }).fill('راجعت الإجابة والشاهدين؛ التصنيف الأدنى مناسب لهذا المثال الاختباري.');
            await button(dialog, 'حفظ تقييم المرحلة').click();
            await dialog.waitFor({ state: 'hidden', timeout: 60000 });
            await go(reviewer, stagePath);
            await visible(reviewer.getByText('بداية تجريبية', { exact: true }), 'manual lowest-category assessment persisted ' + (i + 1));
            if (i === 0 || i === 4) await layout(reviewer, 'maturity-stage-' + (i + 1));
        }
        await go(author, content + '/maturity/' + f.stages[0]);
        check(await button(author, 'تحرير إجابات المرحلة').count() === 0, 'historical initial stage cannot be silently reassessed');
        await go(reviewer, '/institution/mvp-workspace/notifications');
        await visible(reviewer.getByRole('heading', { name: 'إشعاراتي', exact: true, level: 1 }), 'real persisted native inbox');
        await button(reviewer, 'تعليم كمقروء').first().click();
        await visible(reviewer.getByText('مقروء', { exact: true }).first(), 'actual in-app read state');
        await layout(reviewer, 'notifications');
        check([403, 404].includes((await author.context().request.get(f.base + '/institution/mvp-workspace/workflows/' + f.foreign)).status()), 'foreign Approach denied');
        for (const name of ['issuer', 'quality', 'foreign']) {
            const p = await login(name);
            check((await p.context().request.get(f.base + '/institution/mvp-workspace/workflows')).status() === 403, name + ' has no workflow admission');
        }
        check((await reviewer.context().request.get(f.base + '/livewire/upload-file')).status() === 404, 'no upload path');
        check((await reviewer.context().request.get(f.base + '/storage/private')).status() === 404, 'no private serving');
        for (const [p, name] of [[author, 'author'], [reviewer, 'reviewer']]) {
            await go(p, '/institution/' + f.accounts[name].organization + '/account');
            await button(p, 'تسجيل الخروج').click();
            await visible(p.getByRole('group', { name: 'تسجيل الدخول', exact: true }), name + ' original logout');
            check((await p.context().request.get(f.base + content)).status() === 200, 'expired browser session follows actual login redirect');
        }
        check(errors.length === 0, 'no browser JavaScript errors');
        return { passed: true, checks };
    } finally {
        for (const context of contexts) await context.close();
    }
}
