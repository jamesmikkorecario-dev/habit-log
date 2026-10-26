import {test,expect} from './fixtures.js';
import AxeBuilder from '@axe-core/playwright';
import {mkdirSync,writeFileSync} from 'node:fs';
import {register,createHabit,action} from './support.js';
const root='storage/app/checkpoint-editor-checks';
mkdirSync(root,{recursive:true});
const today=new Date().toISOString().slice(0,10);
const yesterday=new Date(Date.now()-86400000).toISOString().slice(0,10);
const observations={states:[],layouts:[],errors:[],axe:[]};
test.afterEach(()=>writeFileSync(`${root}/observations.json`,JSON.stringify(observations,null,2)));
test('Checkpoints polish preserves reactive progress, history, validation and accessible saves',async({page,context,browser})=>{
 test.setTimeout(240000);
 let dismiss=false;
 page.on('dialog',d=>dismiss?d.dismiss():d.accept());
 page.on('pageerror',e=>observations.errors.push(e.message));
 await register(page,'cpreview');
 const save=()=>action(page,()=>page.getByRole('button',{name:'Save activity',exact:true}).click());
 const boxes=()=>page.locator('[data-entry-checkpoint]').getByRole('checkbox');
 const mode=()=>page.getByLabel('Applicability',{exact:true});
 const component=()=>page.locator('[data-entry-editor]');
 async function state(name){
  observations.states.push({name,...await component().evaluate(el=>{
   const c=window.Livewire.find(el.getAttribute('wire:id'));
   return {status:c.$get('form.status'),completed:c.$get('form.completed'),revision:c.$get('entryRevision'),version:c.$get('versionId'),options:[...el.querySelectorAll('select option')].map(o=>({text:o.textContent,value:o.value})),text:el.innerText};
  })});
 }
 async function capture(name){
  for(const width of [1440,390]){
   await page.setViewportSize({width,height:960});
   await page.evaluate(async()=>{await document.fonts.ready;scrollTo(0,0);});
   const layout=await component().evaluate(el=>({width:innerWidth,pageWidth:document.documentElement.scrollWidth,pageHeight:document.documentElement.scrollHeight,rows:[...el.querySelectorAll('[data-entry-checkpoint]')].map(r=>{const b=r.getBoundingClientRect(),l=r.querySelector('[data-flux-label]'),c=r.querySelector('[role=checkbox],input[type=checkbox]');return {x:b.x,width:b.width,height:b.height,labelHeight:l?.getBoundingClientRect().height,checkboxWidth:c?.getBoundingClientRect().width,text:r.innerText};}),saveY:[...el.querySelectorAll('button')].find(b=>b.textContent.includes('Save activity'))?.getBoundingClientRect().y}));
   observations.layouts.push({name,...layout});
   if(name==='twenty-partial-saved'){
    const bottom=page.getByRole('button',{name:'Save activity',exact:true});
    await bottom.scrollIntoViewIfNeeded();await expect(bottom).toBeEnabled();
    await page.screenshot({path:`${root}/${name}-bottom-${width}.png`});
    await page.evaluate(()=>new Promise(resolve=>{scrollTo(0,0);requestAnimationFrame(()=>requestAnimationFrame(resolve));}));
   }
   await page.screenshot({path:`${root}/${name}-${width}.png`,fullPage:true});
  }
 }
 const one=await createHabit(page,'checkpoint','One checkpoint — daily reading');
 await page.goto(`/habits/${one}/entries/${today}`);
 await expect(boxes()).toHaveCount(1);await state('one-new');await capture('one-new');
 await boxes().first().focus();await page.keyboard.press('Space');await expect(boxes().first()).toBeChecked();
 observations.keyboard={spaceToggles:true,focusStyle:await boxes().first().evaluate(el=>({outline:getComputedStyle(el).outline,boxShadow:getComputedStyle(el).boxShadow}))};
 await save();await page.reload();await expect(boxes().first()).toBeChecked();await expect(mode()).toHaveValue('completed');
 await page.goto(`/habits/${one}`);await expect(page.locator(`[data-date="${today}"]`)).toHaveAttribute('aria-label',/100\.0% performance/);
 const id=await createHabit(page,'checkpoint','Evening routine — twenty checkpoints');
 observations.habit=id;
 await page.goto(`/habits/${id}/edit`);await page.getByLabel('Start date',{exact:true}).fill(yesterday);
 const labels=['Read ten pages before going to sleep','Reflect on what went well and what could be improved tomorrow'.padEnd(80,'!'),'W'.repeat(80),...Array.from({length:17},(_,i)=>`Step ${i+4}: Prepare for tomorrow` )];
 await page.getByLabel('Checkpoint 1',{exact:true}).fill(labels[0]);
 for(let i=1;i<20;i++){
  await action(page,()=>page.getByRole('button',{name:'Add checkpoint',exact:true}).click());
  await page.getByLabel(`Checkpoint ${i+1}`,{exact:true}).fill(labels[i]);
 }
 observations.addAtLimit={count:20,disabled:await page.getByRole('button',{name:'Add checkpoint',exact:true}).isDisabled()};
 await page.getByRole('button',{name:'Save habit',exact:true}).click();await page.waitForURL(new RegExp(`/habits/${id}$`));
 const url=`/habits/${id}/entries/${today}`;
 await page.goto(url);await expect(boxes()).toHaveCount(20);await expect(mode()).toHaveValue('missed');
 await expect(page.locator('[data-checkpoint-progress]')).toHaveText('0 of 20 completed · 0% · Missed');
 await expect(page.locator('[data-checkpoint-summary]')).toContainText('Not saved · Current selection');
 await state('twenty-new');await capture('twenty-new');
 const scan=await new AxeBuilder({page}).withTags(['wcag2a','wcag2aa','wcag21aa']).analyze();observations.axe=scan.violations.map(v=>({id:v.id,impact:v.impact,nodes:v.nodes.map(n=>n.target)}));
 await boxes().first().focus();await page.keyboard.press('Space');await expect(boxes().first()).toBeChecked();await page.keyboard.press('Tab');await expect(boxes().nth(1)).toBeFocused();
 await page.keyboard.press('Space');await expect(boxes().nth(1)).toBeChecked();await expect(page.getByText('Unsaved changes',{exact:true})).toBeVisible();
 await expect(page.locator('[data-checkpoint-progress]')).toHaveText('2 of 20 completed · 10% · Partial');
 await expect(page.locator('[data-checkpoint-summary]')).toContainText('Unsaved preview');
 await state('twenty-partial-draft');await capture('twenty-partial-draft');
 // Discard cancellation preserves the synthetic draft, then explicit save persists it.
 dismiss=true;
 await Promise.all([page.waitForEvent('dialog'),page.getByRole('button',{name:'Reload entry',exact:true}).click()]);
 await expect(boxes().first()).toBeChecked();
 await page.getByLabel('Entry date',{exact:true}).fill(yesterday);
 await Promise.all([page.waitForEvent('dialog'),action(page,()=>page.getByRole('button',{name:'Go to date',exact:true}).click())]);
 await expect(page).toHaveURL(new RegExp(url+'$'));dismiss=false;
 await page.locator('#entry-note').fill('Synthetic private checkpoint note');await save();await expect(mode()).toHaveValue('partial');
 await expect(mode().locator('option')).toHaveCount(2);
 await expect(page.locator('[data-checkpoint-summary]')).toContainText('Saved activity');
 await state('twenty-partial-saved');await capture('twenty-partial-saved');
 await page.reload();await expect(boxes().nth(1)).toBeChecked();
 await page.goto(`/habits/${id}`);await expect(page.locator(`[data-date="${today}"]`)).toHaveAttribute('aria-label',/10\.0% performance/);
 await page.goto(url);
 for(const box of await boxes().all())await box.check();
 await expect(page.locator('[data-checkpoint-progress]')).toHaveText('20 of 20 completed · 100% · Completed');
 // Both submit controls share the same form and pending-request lock.
 let release,requests=0;
 const held=new Promise(resolve=>{release=resolve;});
 await page.route('**/livewire*/update',async route=>{requests++;await held;await route.continue();});
 try {
  await page.locator('[data-checkpoint-save]').click();
  await expect(page.locator('[data-checkpoint-save]')).toBeDisabled();
  await expect(page.getByRole('button',{name:'Saving…',exact:true})).toBeDisabled();
  await expect(page.locator('#entry-note')).toBeDisabled();
  await expect(boxes().first()).toBeDisabled();
  await boxes().first().evaluate(el=>el.click());
  await expect(boxes().first()).toBeChecked();
  // A disabled bottom button cannot issue a second submission, even with a raw click.
  await page.getByRole('button',{name:'Saving…',exact:true}).evaluate(el=>el.click());
  expect(requests).toBe(1);
 } finally {release();}
 await expect(page.getByText('Activity saved.',{exact:true})).toBeVisible();
 await page.unroute('**/livewire*/update');expect(requests).toBe(1);
 await expect(mode()).toHaveValue('completed');await expect(mode().locator('option')).toHaveCount(2);
 await state('twenty-completed');await capture('twenty-completed');
 await page.goto(`/habits/${id}`);await expect(page.locator(`[data-date="${today}"]`)).toHaveAttribute('aria-label',/100\.0% performance/);
 await page.goto(url);await action(page,()=>mode().selectOption('not_applicable'));await expect(boxes()).toHaveCount(0);
 await page.locator('#entry-reason').fill('Synthetic neutral day');await save();await page.reload();await expect(page.locator('#entry-reason')).toHaveValue('Synthetic neutral day');
 await expect(page.locator('[data-checkpoint-progress]')).toHaveText('Not applicable · Neutral');
 await state('twenty-na');await capture('twenty-na');
 await page.goto(`/habits/${id}`);await expect(page.locator(`[data-date="${today}"]`)).toHaveAttribute('data-state','na');
 await page.goto(url);await action(page,()=>mode().selectOption('missed'));await expect(boxes()).toHaveCount(20);
 for(const box of await boxes().all())await expect(box).toBeChecked();
 for(const box of await boxes().all())await box.uncheck();await save();await expect(mode()).toHaveValue('missed');
 await state('twenty-missed');await capture('twenty-missed');
 await page.goto(`/habits/${id}`);await expect(page.locator(`[data-date="${today}"]`)).toHaveAttribute('aria-label',/0\.0% performance/);
 await page.goto(url);
 // Native maxlength is inspected; bypass only in this synthetic browser to inspect the real server error.
 await expect(page.locator('#entry-note')).toHaveAttribute('maxlength','2000');
 await page.locator('#entry-note').evaluate(el=>el.removeAttribute('maxlength'));await page.locator('#entry-note').fill('x'.repeat(2001));await save();
 await expect(page.locator('p[role=alert]')).toContainText('Review the highlighted fields');
 await expect(page.getByText('Keep your private note to 2,000 characters or fewer.',{exact:true})).toBeVisible();
 await expect(page.locator('p[role=alert]')).toBeFocused();
 await state('note-validation');await capture('note-validation');
 await page.getByRole('button',{name:'Reload entry',exact:true}).click();await expect(page.locator('p[role=alert]')).toHaveCount(0);
 await component().evaluate(el=>window.Livewire.find(el.getAttribute('wire:id')).$set('form.completed',['99999999'],false));await save();
 await expect(page.locator('p[role=alert]')).toContainText('Review the highlighted fields');
 await expect(page.getByText('One or more checkpoint selections are invalid. Reload this entry and try again.',{exact:true})).toHaveCount(1);
 await expect(page.locator('#checkpoint-selection-error')).toBeVisible();
 await expect(boxes().first()).toHaveAttribute('aria-invalid','true');
 const invalidScan=await new AxeBuilder({page}).withTags(['wcag2a','wcag2aa','wcag21aa']).analyze();
 expect(invalidScan.violations).toEqual([]);
 await state('checkpoint-id-validation');await capture('checkpoint-id-validation');
 await action(page,()=>page.getByRole('button',{name:'Reload entry',exact:true}).click());
 const stale=await context.newPage();await stale.goto(url);await stale.locator('#entry-note').fill('Stale synthetic draft');
 await boxes().first().check();await save();
 await action(stale,()=>stale.getByRole('button',{name:'Save activity',exact:true}).click());
 await expect(stale.getByText('This day or its settings changed. Reload the entry before saving again.',{exact:true})).toBeVisible();
 await expect(stale.locator('#entry-note')).toHaveValue('Stale synthetic draft');
 await stale.setViewportSize({width:390,height:960});await stale.screenshot({path:`root/stale.png`.replace('root',root),fullPage:true});await stale.close();
 observations.stale={rejected:true,draftPreserved:true};
 await page.goto(`/habits/${id}/entries/${yesterday}`);await boxes().nth(1).check();await save();await state('historical-before');
 await page.goto(`/habits/${id}/edit`);await page.getByLabel('Checkpoint 1',{exact:true}).fill('Tomorrow renamed first checkpoint');
 // Remove the second source checkpoint; its historical snapshot must remain.
 await page.getByRole('button',{name:'Remove checkpoint 2',exact:true}).click();
 await page.getByRole('button',{name:'Save habit',exact:true}).click();await page.waitForURL(new RegExp(`/habits/${id}$`));
 await page.goto(`/habits/${id}/entries/${yesterday}`);await expect(boxes()).toHaveCount(20);await expect(boxes().nth(1)).toBeChecked();
 await expect(page.getByRole('checkbox',{name:labels[0],exact:true})).toBeVisible();await expect(page.getByRole('checkbox',{name:labels[1],exact:true})).toBeVisible();
 await expect(page.locator('[data-checkpoint-progress]')).toHaveText('1 of 20 completed · 5% · Partial');
 await state('historical-after');await capture('historical');
 const touchContext=await browser.newContext({storageState:await context.storageState(),baseURL:'http://127.0.0.1:8017',viewport:{width:390,height:844},isMobile:true,hasTouch:true,reducedMotion:'reduce'});
 const touch=await touchContext.newPage();await touch.goto(url);
 const touchBox=touch.locator('[data-entry-checkpoint]').getByRole('checkbox').nth(2);
 await expect(touchBox).not.toBeChecked();await touch.locator('[data-entry-checkpoint] [data-flux-label]').nth(2).tap();await expect(touchBox).toBeChecked();
 await action(touch,()=>touch.getByRole('button',{name:'Save activity',exact:true}).click());await touch.reload();await expect(touchBox).toBeChecked();
 observations.touch={labelTapSavesAndReloads:true};await touchContext.close();
 const tomorrow=new Date(Date.now()+86400000).toISOString().slice(0,10);
 await page.goto(`/habits/${id}/entries/${tomorrow}`);
 await expect(boxes().first()).toBeDisabled();await expect(page.locator('[data-checkpoint-save]')).toHaveCount(0);
 await expect(page.getByRole('button',{name:'Save activity',exact:true})).toHaveCount(0);
 await action(page,()=>page.getByRole('button',{name:'Reload entry',exact:true}).click());
 await expect(boxes().first()).toBeDisabled();
 expect(observations.axe).toEqual([]);
 expect(observations.errors).toEqual([]);
 for(const layout of observations.layouts){
  expect(layout.pageWidth).toBeLessThanOrEqual(layout.width);
  for(const row of layout.rows)expect(row.labelHeight).toBeGreaterThanOrEqual(44);
 }
 observations.errors=observations.errors.filter(Boolean);
 writeFileSync(`${root}/observations.json`,JSON.stringify(observations,null,2));
});
