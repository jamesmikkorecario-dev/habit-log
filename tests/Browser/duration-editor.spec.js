import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { mkdirSync, writeFileSync } from 'node:fs';
import { register, createHabit, action } from './support.js';
const root = 'storage/app/duration-editor-checks';
mkdirSync(root, { recursive: true });
const goalGuidance = 'Performance is calculated from recorded minutes ÷ goal (capped at 100%); assessment is stored separately.';
const freeGuidance = 'Assessment determines performance: Completed 100%, Partial 50%, Missed 0%.';
const today = new Date().toISOString().slice(0,10);
const yesterday = new Date(Date.now()-86400000).toISOString().slice(0,10);

test('Duration polish preserves minutes, scoring, history, privacy and Livewire controls', async ({page, context, browser}) => {
    test.setTimeout(120000);
    const observed = {browser:browser.version(), date:today, layouts:[], validation:[], errors:[]};
    page.on('pageerror', e => observed.errors.push(e.message));
    let dismiss = false;
    page.on('dialog', dialog => dismiss ? dialog.dismiss() : dialog.accept());
    await register(page, 'durationreview');
    async function capture(state) {
        for (const width of [1440,390]) {
            await page.setViewportSize({width,height:960});
            await page.evaluate(async()=>{await document.fonts.ready;scrollTo(0,0);});
            const layout=await page.evaluate(()=>({
                width:innerWidth, overflow:document.documentElement.scrollWidth>innerWidth,
                save:document.querySelector('[data-entry-form] button[type=submit]')?.getBoundingClientRect().toJSON(),
                input:document.querySelector('#entry-value')?.getBoundingClientRect().toJSON(),
            }));
            expect(layout.overflow).toBe(false);
            if(layout.save) expect(layout.save.height).toBeGreaterThanOrEqual(44);
            observed.layouts.push({state,...layout});
            await page.screenshot({path:`${root}/${state}-${width}.png`,fullPage:true});
        }
    }
    async function save() {
        await action(page,()=>page.getByRole('button',{name:'Save activity',exact:true}).click());
    }
    const id=await createHabit(page,'duration','Exercise for 30 minutes');
    observed.goalHabit=id;
    await page.goto(`/habits/${id}/edit`);
    await page.getByLabel('Start date',{exact:true}).fill(yesterday);
    await page.getByLabel('Goal (optional)',{exact:true}).fill('30');
    await page.getByRole('button',{name:'Save habit',exact:true}).click();
    await page.waitForURL(new RegExp(`/habits/${id}$`));
    const url=`/habits/${id}/entries/${today}`;
    await page.goto(url);
    const outcome=page.getByLabel('Your assessment',{exact:true});
    const minutes=page.getByLabel('Actual minutes',{exact:true});
    await expect(outcome).toHaveValue('');
    await expect(minutes).toHaveValue('');
    await expect(page.getByText('Goal: 30 minutes',{exact:true})).toBeVisible();
    await expect(page.locator('[data-terminal-path]')).toHaveAttribute('data-terminal-path','~/tracking/edit');
    await expect(page.getByText(goalGuidance,{exact:true})).toBeVisible();
    await capture('duration-new');
    await page.locator('#entry-note').fill('PRIVATE-DURATION-REVIEW-NOTE');
    await save();
    await expect(page.getByText('Choose an assessment.',{exact:true})).toBeVisible();
    await expect(page.getByText('Enter the actual minutes.',{exact:true})).toBeVisible();
    await expect(page.locator('#entry-note')).toHaveValue('PRIVATE-DURATION-REVIEW-NOTE');
    await expect(page.getByText('Review the highlighted fields. Your changes have not been saved.',{exact:true})).toBeFocused();
    observed.errorPlacement=await page.evaluate(()=>{
        const input=document.querySelector('#entry-value');
        const error=[...document.querySelectorAll('[data-flux-error]')].find(el=>el.textContent.includes('Enter the actual minutes.'));
        return {inputBottom:input.getBoundingClientRect().bottom,errorTop:error.getBoundingClientRect().top,describedBy:input.getAttribute('aria-describedby')};
    });
    expect(observed.errorPlacement.errorTop - observed.errorPlacement.inputBottom).toBeGreaterThanOrEqual(0);
    expect(observed.errorPlacement.errorTop - observed.errorPlacement.inputBottom).toBeLessThanOrEqual(16);
    await expect(minutes).toHaveAttribute('aria-describedby', /entry-value-error/);
    await expect(page.locator('main')).not.toContainText('form.status');
    await expect(page.locator('main')).not.toContainText('form.value');
    await capture('duration-required-errors');
    observed.axe=(await new AxeBuilder({page}).withTags(['wcag2a','wcag2aa','wcag21aa']).analyze()).violations.map(v=>({id:v.id,impact:v.impact,description:v.description}));
    await action(page,()=>outcome.selectOption('partial'));
    for(const value of ['-1','1.5','1000000000']) {
        await minutes.fill(value);
        const validation=await minutes.evaluate(el=>({valid:el.checkValidity(),message:el.validationMessage}));
        expect(validation.valid).toBe(false);
        observed.validation.push({value,...validation});
        // Exercise the real server boundary as well as native number validation.
        await page.locator('[data-entry-form]').evaluate(el=>el.noValidate=true);
        await save();
        await expect(page.getByText('Enter a whole number from 0 to 999,999,999.',{exact:true})).toBeVisible();
    }
    await capture('duration-invalid-number');
    await page.locator('[data-entry-form]').evaluate(el=>el.noValidate=false);
    for(const [value,status,score] of [['0','completed','0.0'],['15','partial','50.0'],['30','completed','100.0'],['45','missed','100.0']]) {
        await minutes.fill(value);
        await action(page,()=>outcome.selectOption(status));
        if(value==='15') {
            let release;
            const held=new Promise(r=>release=r);
            await page.route('**/livewire*/update',async route=>{await held;await route.continue();},{times:1});
            await page.getByRole('button',{name:'Save activity',exact:true}).click();
            try {
                await expect(page.getByRole('button',{name:'Saving…',exact:true})).toBeDisabled();
                await expect(minutes).toBeDisabled();
            } finally { release(); }
            await expect(page.getByText('Activity saved.',{exact:true})).toBeVisible();
        } else await save();
        await page.goto(url);
        await expect(minutes).toHaveValue(value);
        await expect(outcome).toHaveValue(status);
        if(value==='15') await capture('duration-saved');
        await page.goto(`/habits/${id}`);
        await expect(page.locator(`[data-date="${today}"]`)).toHaveAttribute('aria-label',new RegExp(`${score}% performance`));
        await page.goto(url);
    }
    await action(page,()=>outcome.selectOption('not_applicable'));
    await expect(minutes).toHaveCount(0);
    await page.locator('#entry-reason').fill('PRIVATE-DURATION-NA-REASON');
    await save(); await page.reload();
    await expect(page.locator('#entry-reason')).toHaveValue('PRIVATE-DURATION-NA-REASON');
    await capture('duration-na');
    await page.goto(`/habits/${id}`);
    await expect(page.locator(`[data-date="${today}"]`)).toHaveAttribute('data-state','na');
    await page.goto(url);
    await action(page,()=>outcome.selectOption('partial'));
    await expect(minutes).toHaveValue('45');
    await save();
    // Confirmed reload and cancelled date navigation preserve normal draft behavior.
    await page.locator('#entry-note').fill('Unsaved duration draft');
    dismiss=true;
    await Promise.all([
        page.waitForEvent('dialog'),
        page.getByRole('button',{name:'Reload entry',exact:true}).click(),
    ]);
    await expect(page.locator('#entry-note')).toHaveValue('Unsaved duration draft');
    await page.getByLabel('Entry date',{exact:true}).fill(yesterday);
    await Promise.all([
        page.waitForEvent('dialog'),
        action(page,()=>page.getByRole('button',{name:'Go to date',exact:true}).click()),
    ]);
    await expect(page).toHaveURL(new RegExp(url+'$'));
    dismiss=false;
    await action(page,()=>page.getByRole('button',{name:'Reload entry',exact:true}).click());
    await expect(page.locator('#entry-note')).toHaveValue('PRIVATE-DURATION-REVIEW-NOTE');
    const stale=await context.newPage();await stale.goto(url);
    await page.locator('#entry-note').fill('PRIVATE-DURATION-REVIEW-NOTE updated');await save();
    await stale.locator('#entry-note').fill('Stale private draft');
    await action(stale,()=>stale.getByRole('button',{name:'Save activity',exact:true}).click());
    await expect(stale.getByText('This day or its settings changed. Reload the entry before saving again.',{exact:true})).toBeVisible();
    await expect(stale.locator('#entry-note')).toHaveValue('Stale private draft');await stale.close();

    await page.goto(`/habits/${id}/entries/${yesterday}`);
    await action(page,()=>outcome.selectOption('partial'));
    await minutes.fill('15');await save();
    await page.goto(`/habits/${id}/edit`);
    await page.getByLabel('Goal (optional)',{exact:true}).fill('60');
    await page.getByRole('button',{name:'Save habit',exact:true}).click();
    await page.waitForURL(new RegExp(`/habits/${id}$`));
    await expect(page.locator(`[data-date="${yesterday}"]`)).toHaveAttribute('aria-label',/50.0% performance/);
    await page.goto(`/habits/${id}/entries/${yesterday}`);
    await expect(page.getByText('Goal: 30 minutes',{exact:true})).toBeVisible();
    await expect(minutes).toHaveValue('15');
    await expect(page.getByText(goalGuidance,{exact:true})).toBeVisible();
    await capture('duration-historical');
    await expect(page.getByRole('link',{name:'Daily overview',exact:true})).toHaveAttribute('href',new RegExp(`tracking\\?date=${yesterday}$`));
    await expect(page.getByRole('link',{name:'Entry history',exact:true})).toHaveAttribute('href',new RegExp(`/habits/${id}/entries$`));

    const free=await createHabit(page,'duration','Move at my own pace');observed.goalFreeHabit=free;
    const freeUrl=`/habits/${free}/entries/${today}`;
    await page.goto(freeUrl);
    await expect(page.getByText('Goal: Not set',{exact:true})).toBeVisible();
    await expect(page.getByText(freeGuidance,{exact:true})).toBeVisible();
    await capture('duration-no-goal');
    for(const [status,score] of [['completed','100.0'],['partial','50.0'],['missed','0.0']]) {
        await action(page,()=>outcome.selectOption(status));await minutes.fill('15');await save();
        await page.goto(`/habits/${free}`);
        await expect(page.locator(`[data-date="${today}"]`)).toHaveAttribute('aria-label',new RegExp(`${score}% performance`));
        await page.goto(freeUrl);
    }
    for(const type of ['boolean','count']) {
        const reference=await createHabit(page,type,`Review reference ${type}`);
        await page.goto(`/habits/${reference}/entries/${today}`);
        await capture(`reference-${type}`);
    }
    const foreignContext=await browser.newContext({baseURL:'http://127.0.0.1:8017'});
    const foreign=await foreignContext.newPage();await register(foreign,'durationother');
    const response=await foreign.goto(url);
    expect(response.status()).toBe(404);
    await expect(foreign.locator('body')).not.toContainText('PRIVATE-DURATION-REVIEW-NOTE');
    await foreignContext.close();
    const guestContext=await browser.newContext({baseURL:'http://127.0.0.1:8017'});
    const guest=await guestContext.newPage();await guest.goto(url);await expect(guest).toHaveURL(/\/login$/);
    await guestContext.close();
    expect(observed.errors).toEqual([]);
    expect(observed.axe).toEqual([]);
    writeFileSync(`${root}/observations.json`,JSON.stringify(observed,null,2));
});
