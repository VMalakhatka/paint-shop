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
    const hiddenOdesaPlaceholder = row => ['ODESA_SALARY_RUB','ODESA_BANK_SERVICES','ODESA_TAXES','ODESA_ACCOUNTING'].includes(row.lineId) && row.source==='NOT_APPLICABLE' && row.amount!=null && row.profitImpact!=null && Number(row.amount)===0 && Number(row.profitImpact)===0;
    // Stable Excel row addresses match the owner's manual workbook with the duplicate row removed on 2026-10-02.
    // H belongs to the manager. I is always the authoritative API snapshot.
    function manualTemplateSheets(data, t) {
        let sequence=0;
        return ['KYIV','ODESA'].map(city=>{
            const kyiv=city==='KYIV', name=kyiv?t.kyiv:t.odesa, end=kyiv?39:32;
            const rows=Array.from({length:end},()=>Array(9).fill('')), headings=[1,3], merges=[], used=new Set();
            const result=(data.cities||[]).find(c=>c.city===city)||{};
            rows[0]=['№',t.fields.label,t.fields.expenseCodes,t.fields.operationTypes,t.fields.purposeCodes,t.fields.cashWarehouses,t.fields.bankWarehouses,t.manualTemplateAmount,t.siteAmount];
            rows[1]=['',t.reportMonth,'','','','','',data.month,data.month];
            rows[2][1]=name+' — '+t.cityExpenses;
            const put=(r,label,amount)=>{rows[r-1][0]=++sequence;rows[r-1][1]=label;rows[r-1][8]=number(amount);};
            const lines=Array.isArray(data.expenseLines)?data.expenseLines:[];
            const expense=(r,id,fallback)=>{
                used.add(id);const line=lines.find(l=>l.lineId===id&&l.city===city);
                put(r,line?.label||fallback||t.unavailable,line?.amount);
                if(!line){rows[r-1][2]=t.templateRuleUnavailable;return;}
                const f=line.filters, na=line.source==='NOT_APPLICABLE',manual=line.source&&line.source!=='FOLIO';
                rows[r-1][2]=na?t.doNotFill:(f?.expenseCodes||[]).join(' / ')||(manual?t.manualAmount:'—');
                rows[r-1][3]=manual?'—':(f?.operationTypes||[]).join(' / ')||t.anyFilter;
                rows[r-1][4]=manual?'—':(f?.purposeCodes||[]).join(' / ')||(/_TAXES$/.test(id)?t.noTaxFirms:t.anyFilter);
                rows[r-1][5]=manual?'—':selection(f,'cash',t);rows[r-1][6]=manual?'—':selection(f,'bank',t);
            };
            const layout=kyiv?[
                [4,'RENT_SHOP'],[5,'UTILITIES'],[6,'SALARY_UAH'],[7,'SALARY_RUB'],[8,'ADDITIONAL_SALARY'],
                [9,'HOUSEHOLD',t.householdServices],[10,'ACCOUNTING'],[11,'ADVERTISING'],
                [12,'TRANSPORT_UKRAINE'],[13,'INTERNET'],[14,'PHONE'],[15,'BANK_SERVICES'],[16,'TAXES'],
                [17,'IRREGULAR'],[18,'RENT_WHOLESALE'],[19,'PHONE_KAL']
            ]:[[4,'RENT'],[5,'UTILITIES'],[6,'SALARY_DOCUMENTS'],[7,'ADDITIONAL_SALARY'],[8,'HOUSEHOLD'],
                [9,'ADVERTISING'],[10,'TRANSPORT_UKRAINE'],[11,'INTERNET'],[12,'PHONE']];
            layout.forEach(([r,id,label])=>expense(r,city+'_'+id,label));
            const total=kyiv?21:14, master=kyiv?25:18, base=kyiv?31:24, gross=kyiv?37:30, op=gross+1, profit=gross+2;
            put(total,t.fields.operatingExpenses,result.operatingExpenses);headings.push(total);
            if(kyiv)expense(23,'KYIV_IMPORT_TRANSPORT');
            rows[master-1][1]=t.masterTitle;headings.push(master);
            const mk=masterClasses(data)[city];
            [['income',t.masterIncome],['returns',t.masterReturns],['netContribution',t.masterNet]].forEach(([key,label],i)=>{
                put(master+i+1,label,mk?.[key]);
                if(i<2){const row=rows[master+i];row[2]=mk?.sku||'—';row[3]=i?t.returnInvoice:t.outgoingInvoice;row[4]='—';row[5]=mk?.warehouseId??'—';row[6]='—';}
            });
            rows[base-2][1]=t.profitTotals;headings.push(base-1);
            put(base,t.fields.baseGrossProfit+' — '+name,result.baseGrossProfit);
            const grossLines=(data.grossProfitLines||[]).filter(l=>l.city===city);
            rows[base-1][5]=(grossLines[0]?.warehouseIds||[]).join(' / ');
            ['OWN_SHOPS','PARTNERS','DEALERS','CUSTOMERS','ART_SALONS'].forEach((key,i)=>{
                const id=city+'_GROSS_'+key,line=grossLines.find(l=>l.lineId===id);used.add(id);
                put(base+i+1,t.fields.baseGrossProfit+' — '+(line?.label||t.grossCategoryLabels?.[key]||key),line?.amount);
                rows[base+i][2]=(line?.organizationTypes||[]).join(' / ');rows[base+i][5]=(line?.warehouseIds||[]).join(' / ');
            });
            put(gross,t.fields.grossProfit+' — '+name,result.grossProfit);put(op,t.fields.operatingExpenses+' — '+name,result.operatingExpenses);put(profit,t.fields.profit+' — '+name,result.profit);
            // Keep the owner's manual formulas, adjusting references for the deleted Kyiv row; never calculate API totals here.
            const formulas=kyiv?{21:'SUM(H4:H20)',28:'H26-H27',37:'SUM(H28:H36)',38:'H21',39:'H37-H38'}:
                {14:'SUM(H4:H13)',21:'H19-H20',30:'SUM(H21:H29)',31:'H14',32:'H30-H31'};
            Object.entries(formulas).forEach(([r,f])=>{rows[Number(r)-1][7]=formula(f,0);});
            headings.push(master+3,gross,op,profit);
            // Do not discard an unexpected/historical nonzero line just to fit the manual template.
            const extras=lines.filter(l=>l.city===city&&!used.has(l.lineId)&&!hiddenOdesaPlaceholder(l)&&
                (l.amount==null||l.profitImpact==null||Number(l.amount)!==0||Number(l.profitImpact)!==0));
            const grossExtras=grossLines.filter(l=>!used.has(l.lineId)&&(l.amount==null||Number(l.amount)!==0));
            const notes=[t.manualTemplateHelp,t.manualGrossHelp,t.managerCriteriaHelp,t.currentRulesHelp,
                t.snapshot+': '+snapshotDate(data.calculatedAt)+' · '+(data.complete?t.complete:t.incomplete)];
            if(extras.length||grossExtras.length){
                rows.push(['',t.templateExtraRows]);headings.push(rows.length);
                extras.forEach(line=>expense(rows.push(Array(9).fill('')),line.lineId));
                grossExtras.forEach(line=>{rows.push(Array(9).fill(''));put(rows.length,t.fields.baseGrossProfit+' — '+line.label,line.amount);});
                notes.unshift(t.templateExtraHelp);
            }
            notes.forEach(note=>{rows.push([note]);merges.push(rows.length);});
            return {name,city,rows,widths:[7,40,28,28,30,18,18,23,23],headerRows:headings,freezeRows:2,mergeRows:merges,rowHeight:28,landscape:true,bordered:true,manualColumn:7,reportColumn:8,copyStartRow:4,copyEndRow:end};
        });
    }
    function sheets(data, t) {
        if(currentLayout(data))return manualTemplateSheets(data,t);
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
