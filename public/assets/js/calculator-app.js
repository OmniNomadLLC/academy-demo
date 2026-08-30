//Listen for submit
document.getElementById('loan-form').addEventListener('submit',function (e) {
    //hide results
    document.getElementById('results').style.display = 'none';
    //show result
    document.getElementById('data-loading').style.display = 'block';
    setTimeout(calculateResults,2000);
    e.preventDefault();
});

// Calculate Results
function calculateResults(){
    console.log('hello');
    //UI variable
    const UIamount = document.getElementById('amount');
    const UIdownpayment = document.getElementById('down-payment');
    const UIinterest = document.getElementById("interest");
    const UIyears = document.getElementById("years");
    const UImonthlyPayment = document.getElementById("monthly-payment");
    const UItotalPayment= document.getElementById("total-payment");
    const UItotalInterest = document.getElementById("total-interest");

    const principal = parseFloat(UIamount.value);
    const downprincipal = parseFloat(UIdownpayment.value);
    const calculatedInterest = parseFloat(UIinterest.value)/100 / 12 ;
    const calculatedPayments = parseFloat(UIyears.value)*12;

    //complate monthly payment
    const x = Math.pow(1 + calculatedInterest,calculatedPayments);
    const monthly = ((principal-downprincipal)*x*calculatedInterest)/(x-1);
    
    if(principal < 0)
    {
        showError('Please Enter Positive Amount for Principal');
    }
    else if(calculatedInterest < 0)
    {
        showError('Please Enter Positive Interest Rate');
    }
    else if(calculatedPayments  < 0)
    {
        showError('Please Enter Positive Value');
    }
    else if(isFinite(monthly)){
        UImonthlyPayment.value = monthly.toFixed(2);
        UItotalPayment.value = (downprincipal+monthly*calculatedPayments).toFixed(2);
        UItotalInterest.value = ((monthly * calculatedPayments)-(principal-downprincipal)).toFixed(2);
        //show result
        document.getElementById('results').style.display = 'flex';
        //hide loader
        document.getElementById('data-loading').style.display = 'none';
    }else{
        
        showError('Please check your number');
    }


}


//Show Error

function showError(error){
    //hide result
    document.getElementById('results').style.display = 'none';
    //hide loader
    document.getElementById('data-loading').style.display = 'none';
    //create a div
    const errorDiv =document.createElement('div');

    //get Element
    const card = document.querySelector('.card');
    const heading =document.querySelector('.heading');

    //add class
    errorDiv.className = 'alert alert-danger';

    //  Create text node and append to div
    errorDiv.appendChild(document.createTextNode(error));

    //Insert Error above heading
    card.insertBefore(errorDiv,heading);

    //clear Error after 3second
    setTimeout(clearError,2000);
}

function clearError()
{
    document.querySelector('.alert').remove();
}
