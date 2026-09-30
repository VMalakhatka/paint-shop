/* Manager workbook layout. Values come from Java; editable Excel tax formulas mirror its document rounding. */
(function (scope) {
    'use strict';
    const number = (value, money = true) => value == null ? '—' : {type:'number', value:String(value), money};
    const formula = (expression, value, money = true) => ({type:'formula', formula:expression, value:String(value), money});
    const taxLabel = (row, t) => /_TAX_MALAFOP$/.test(row.lineId || '') ? t.retailTax : /_TAX_KONDFOP$/.test(row.lineId || '') ? t.wholesaleTax : row.label;
    const purpose = (row, settings) => {
        const retail=/_TAX_MALAFOP$/.test(row.lineId || ''), wholesale=/_TAX_KONDFOP$/.test(row.lineId || '');
        if(settings && (retail || wholesale))return settings[retail?'retailFirmCodes':'wholesaleFirmCodes'];
        if(Array.isArray(row.filters?.purposeCodes) && (row.filters.purposeCodes.length || !(retail || wholesale)))return row.filters.purposeCodes;
        return retail?['МАЛАФОП']:wholesale?['КОНДФОП']:row.filters?.purposeCodes;
    };
    const selection = (f, key, t) => !f ? '—' : f[key+'WarehouseMode']==='ALL' ? t.allWarehouses : f[key+'WarehouseMode']==='NONE' ? '—' : (f[key+'WarehouseMode']==='EXCLUDE' ? t.exceptWarehouses+': ' : '')+(f[key+'WarehouseIds'] || []).join(', ');
    const currentLayout = data => data.inputs?.taxAllocationMethod === 'ALL_TAXES_KYIV';
    const masterClasses = data => data.masterClassesByCity && typeof data.masterClassesByCity === 'object' ? data.masterClassesByCity : {ODESA:data.masterClass};
    const masterDocuments = data => data.masterClassDocumentsByCity && typeof data.masterClassDocumentsByCity === 'object'
        ? Object.entries(data.masterClassDocumentsByCity).flatMap(([city, rows]) => Array.isArray(rows)?rows.map(row=>({...row,city})):[])
        : (data.masterClassDocuments || []).map(row=>({...row,city:'ODESA'}));
    function snapshotDate(value) {
        const numeric=Number(value), date=new Date(value==null?NaN:Number.isFinite(numeric)?(numeric<100000000000?numeric*1000:numeric):value);
        return Number.isNaN(date.getTime())?'—':new Intl.DateTimeFormat('uk-UA',{timeZone:'Europe/Kyiv',dateStyle:'short',timeStyle:'short'}).format(date);
    }
    function templateSheets(data, t) {
        let sequence=0;
        return ['KYIV','ODESA'].map(city=>{
            const name=city==='KYIV'?t.kyiv:t.odesa, rows=[], headings=[], merges=[];
            const heading=label=>{rows.push(['',label]);headings.push(rows.length);};
            const amountRow=(label,amount,expression,warehouses='')=>{
                rows.push([++sequence,label,'','','',warehouses,'',expression&&amount!=null?formula(expression,amount):number(amount),'']);return rows.length;
            };
            heading(name);rows[0][7]=data.month;
            rows.push(['',t.snapshot,'',snapshotDate(data.calculatedAt),'','','',data.complete?t.complete:t.incomplete,'']);
            rows.push(['№',t.fields.label,t.fields.expenseCodes,t.fields.operationTypes,t.fields.purposeCodes,t.fields.cashWarehouses,t.fields.bankWarehouses,t.siteAmount,t.managerCheck]);headings.push(3);
            const lines=(data.expenseLines||[]).filter(r=>r.city===city).slice().sort((a,b)=>(a.sortOrder||0)-(b.sortOrder||0));
            const wholesale=line=>['KYIV_RENT_WHOLESALE','KYIV_PHONE_KAL'].includes(line.lineId);
            const capitalized=line=>line.accountingTreatment==='CAPITALIZED_IN_INVENTORY';
            const refs=[];
            const lineRow=line=>{
                const f=line.filters, notApplicable=line.source==='NOT_APPLICABLE', manual=line.source&&line.source!=='FOLIO';
                rows.push([++sequence,line.label,notApplicable?t.doNotFill:(f?.expenseCodes||[]).join(' / ')||(manual?t.manualAmount:'—'),
                    manual?'—':(f?.operationTypes||[]).join(' / ')||t.anyFilter,
                    manual?'—':(f?.purposeCodes||[]).join(' / ')||(/_TAXES$/.test(line.lineId)?t.noTaxFirms:t.anyFilter),
                    manual?'—':selection(f,'cash',t),manual?'—':selection(f,'bank',t),number(line.amount),'']);
                if(line.accountingTreatment==='OPERATING_EXPENSE')refs.push('H'+rows.length);
            };
            heading(t.cityExpenses);
            lines.filter(l=>!wholesale(l)&&!capitalized(l)).forEach(lineRow);
            const opt=lines.filter(wholesale);
            if(opt.length){heading(t.wholesaleSection);opt.forEach(lineRow);}
            const result=(data.cities||[]).find(c=>c.city===city)||{};
            const operating=lines.filter(l=>l.accountingTreatment==='OPERATING_EXPENSE');
            const canSum=data.sections?.EXPENSES?.status!=='UNAVAILABLE'&&operating.length&&operating.every(l=>l.amount!=null&&l.profitImpact!=null&&Number(l.amount)===Number(l.profitImpact));
            const expenses=amountRow(t.fields.operatingExpenses,result.operatingExpenses,canSum?'SUM('+refs.join(',')+')':null);headings.push(expenses);
            lines.filter(capitalized).forEach(lineRow);
            heading(t.masterTitle);
            const mk=masterClasses(data)[city];
            const mkRefs={};
            for(const [key,label] of [['income',t.masterIncome],['returns',t.masterReturns],['netContribution',t.masterNet],['grossProfitAlreadyInBase',t.masterBase],['grossAdjustmentApplied',t.masterAdjustment]]){
                const expression=key==='netContribution'&&mk?.income!=null&&mk?.returns!=null?`H${mkRefs.income}-H${mkRefs.returns}`:key==='grossAdjustmentApplied'&&mk?.netContribution!=null&&mk?.grossProfitAlreadyInBase!=null?`H${mkRefs.netContribution}-H${mkRefs.grossProfitAlreadyInBase}`:null;
                mkRefs[key]=amountRow(label,mk?.[key],expression);
                if(key==='income'||key==='returns'){
                    const row=rows[rows.length-1];row[2]=mk?.sku||'—';row[3]=key==='income'?t.outgoingInvoice:t.returnInvoice;row[4]='—';row[5]=mk?.warehouseId??'—';row[6]='—';
                }
            }
            heading(t.profitTotals);
            const grossLines=(data.grossProfitLines||[]).filter(r=>r.city===city).slice().sort((a,b)=>(a.sortOrder||0)-(b.sortOrder||0));
            const base=amountRow(t.fields.baseGrossProfit+' — '+name,result.baseGrossProfit,null,(grossLines[0]?.warehouseIds||[]).join(' / '));
            grossLines.forEach(line=>{
                amountRow(t.fields.baseGrossProfit+' — '+line.label,line.amount,null,(line.warehouseIds||[]).join(' / '));
                rows[rows.length-1][2]=(line.organizationTypes||[]).join(' / ');
            });
            // City totals remain authoritative, even when one source/section is unavailable.
            const gross=amountRow(t.fields.grossProfit+' — '+name,result.grossProfit,result.baseGrossProfit!=null&&mk?.grossAdjustmentApplied!=null&&Number(mk.grossAdjustmentApplied)===Number(result.manualGrossAdjustments)?`H${base}+H${mkRefs.grossAdjustmentApplied}`:null);
            const op=amountRow(t.fields.operatingExpenses+' — '+name,result.operatingExpenses,`H${expenses}`);
            amountRow(t.fields.profit+' — '+name,result.profit,result.grossProfit!=null&&result.operatingExpenses!=null?`H${gross}-H${op}`:null);
            rows.push([t.managerCriteriaHelp]);merges.push(rows.length);
            rows.push([t.currentRulesHelp]);merges.push(rows.length);
            rows.push([t.managerCurrentHelp]);merges.push(rows.length);
            return {name,rows,widths:[7,40,28,28,28,20,20,21,24],headerRows:headings,freezeRows:3,mergeRows:merges,rowHeight:28,landscape:true,bordered:true};
        });
    }
    function sheets(data, t) {
        if(currentLayout(data))return templateSheets(data,t);
        return ['KYIV','ODESA'].map(city => {
            const inputs=data.inputs || {}, k=inputs.kyivEmployeeCount, o=inputs.odesaEmployeeCount;
            const counts=Number.isInteger(k)&&Number.isInteger(o)&&k>=0&&o>=0&&k+o>0;
            const share=inputs.odesaTaxShare == null ? null : Number(inputs.odesaTaxShare);
            const cityShare=share==null?null:city==='ODESA'?share:1-share;
            const dateValue=Number(data.calculatedAt);
            const date=new Date(data.calculatedAt==null?NaN:Number.isFinite(dateValue)?(dateValue<100000000000?dateValue*1000:dateValue):data.calculatedAt);
            const stamp=Number.isNaN(date.getTime())?'—':new Intl.DateTimeFormat('uk-UA',{timeZone:'Europe/Kyiv',dateStyle:'short',timeStyle:'short'}).format(date);
            const rows=[['',city==='KYIV'?t.kyiv:t.odesa,'','','','','',data.month,''], ['',t.snapshot,'',stamp,'','','',data.complete?t.complete:t.incomplete],
                ['',t.employeeTotal,'','','','','',counts?formula('SUM(H4:H5)',k+o,false):'—'],
                ['',t.kyivEmployees,'','','','','',number(k,false)], ['',t.odesaEmployees,'','','','','',number(o,false)],
                ['',t.retailShare,'','','','','',counts?formula(`H${city==='KYIV'?4:5}/H3`,cityShare,false):number(cityShare,false)],
                [t.managerCriteriaHelp],
                ['№',t.fields.label,t.fields.expenseCodes,t.fields.operationTypes,t.fields.purposeCodes,t.fields.cashWarehouses,t.fields.bankWarehouses,t.siteAmount,t.managerCheck]];
            const lines=Array.isArray(data.expenseLines)?data.expenseLines.filter(r=>r.city===city && !(r.lineId==='ODESA_TAX_KONDFOP' && Number(r.amount)===0 && Number(r.profitImpact)===0 && r.documentCount===0)).sort((a,b)=>(a.sortOrder||0)-(b.sortOrder||0)):[];
            const unavailable=data.sections?.EXPENSES?.status==='UNAVAILABLE' || !Array.isArray(data.expenseLines);
            const refs=new Map(), operating=[];
            lines.forEach((line,i)=>{
                const f=line.filters, manual=line.source && line.source!=='FOLIO';
                rows.push([i+1,taxLabel(line,t),(f?.expenseCodes || []).join(' / ') || (manual?t.manualAmount:'—'),manual?'—':(f?.operationTypes || []).join(' / ')||t.anyFilter,manual?'—':(purpose(line,data.taxDetails?.settings)||[]).join(' / ')||(/_TAX_(MALAFOP|KONDFOP)$/.test(line.lineId || '')?t.noTaxFirms:t.anyFilter),manual?'—':selection(f,'cash',t),manual?'—':selection(f,'bank',t),number(line.amount),'']);
                refs.set(line.lineId,rows.length);
                if(line.accountingTreatment==='OPERATING_EXPENSE')operating.push('H'+rows.length);
            });
            if(unavailable)rows.push([t.unavailable]);
            rows.push([]);
            const result=(data.cities||[]).find(c=>c.city===city)||{};
            const amountRow=(label,value,expression)=>{rows.push(['',label,'','','','','',expression && value!=null?formula(expression,value):number(value),'']);return rows.length;};
            const expensesRow=amountRow(t.fields.operatingExpenses,result.operatingExpenses,!unavailable&&operating.length&&lines.filter(l=>l.accountingTreatment==='OPERATING_EXPENSE').every(l=>l.amount!=null&&l.profitImpact!=null&&Number(l.amount)===Number(l.profitImpact))?'SUM('+operating.join(',')+')':null);
            rows.push([]);
            const grossRow=amountRow(t.fields.baseGrossProfit,result.baseGrossProfit);
            const adjustRow=amountRow(t.fields.manualGrossAdjustments,result.manualGrossAdjustments);
            const grossAdjustedRow=amountRow(t.fields.grossProfit,result.grossProfit,result.baseGrossProfit!=null&&result.manualGrossAdjustments!=null?`H${grossRow}+H${adjustRow}`:null);
            amountRow(t.fields.profit,result.profit,result.grossProfit!=null&&result.operatingExpenses!=null?`H${grossAdjustedRow}-H${expensesRow}`:null);
            if(city==='ODESA'){
                rows.push([]);
                ['income','returns','netContribution','grossProfitAlreadyInBase','grossAdjustmentApplied'].forEach(key=>amountRow(t.fields[key]||key,data.masterClass?.[key]));
            }
            rows.push([],[t.taxFormulaHelp]);
            const headingRows=[1,8,expensesRow], mergeRows=[7,rows.length];
            const taxDocs=(data.documents||[]).filter(d=>(d.expenseLineIds||[]).includes(city+'_TAX_MALAFOP') && d.includedInProfit!==false);
            const taxLine=lines.find(l=>l.lineId===city+'_TAX_MALAFOP');
            // Truncated/old audits retain the authoritative amount. Never build a partial SUM.
            const complete=!unavailable&&!data.controls?.auditTruncated&&taxLine&&taxDocs.length===taxLine.documentCount&&new Set(taxDocs.map(d=>String(d.paymentId))).size===taxDocs.length&&share!=null&&taxDocs.every(d=>d.reportAmount!=null&&d[city==='KYIV'?'kyivAllocation':'odesaAllocation']!=null);
            if(complete && taxDocs.length){
                rows.push(['',t.retailTax,t.csvDate,t.csvDocument,'ID','','',t.fields.reportAmount,t.cityTax]);headingRows.push(rows.length);
                const amounts=[];
                taxDocs.forEach(d=>{
                    const n=rows.length+1;
                    const allocation=counts?`H${n}*$H$5/$H$3`:city==='ODESA'?`H${n}*$H$6`:`H${n}*(1-$H$6)`;
                    const expression=city==='ODESA'?`ROUND(${allocation},2)`:`H${n}-ROUND(${allocation},2)`;
                    rows.push(['','',d.documentDate,d.documentNumber,String(d.paymentId),'','',number(d.reportAmount),formula(expression,d[city==='KYIV'?'kyivAllocation':'odesaAllocation'])]);
                    amounts.push('I'+n);
                });
                rows[refs.get(taxLine.lineId)-1][7]=formula('SUM('+amounts.join(',')+')',taxLine.amount);
            }else { rows.push([t.taxFormulaUnavailable]); mergeRows.push(rows.length); }
            rows.push([t.managerEditHelp]); mergeRows.push(rows.length);
            return {name:city==='KYIV'?t.kyiv:t.odesa,rows,widths:[7,40,23,28,22,20,20,21,24],headerRows:headingRows,freezeRows:8,mergeRows,rowHeight:24,landscape:true};
        });
    }
    const api={sheets,taxLabel,purpose,currentLayout,masterClasses,masterDocuments};
    if(typeof module!=='undefined'&&module.exports)module.exports=api;else scope.LavkaProfitManager=api;
})(typeof window!=='undefined'?window:globalThis);
