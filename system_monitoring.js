let quizTrendChart;
let subjectDistributionChart;

function getChartTheme(){

    const styles = getComputedStyle(document.documentElement);

    return {
        primary: styles.getPropertyValue("--primary").trim(),
        text: styles.getPropertyValue("--text").trim(),
        line: styles.getPropertyValue("--border").trim(),
        pale: styles.getPropertyValue("--light").trim(),
        secondary: styles.getPropertyValue("--secondary").trim(),
        dark: styles.getPropertyValue("--dark").trim()
    };

}

function updateChartTheme(chart, isPie = false){

    if(!chart){

        return;

    }

    const theme = getChartTheme();

    chart.options.plugins.legend.labels.color = theme.text;
    chart.options.plugins.tooltip.titleColor = theme.text;
    chart.options.plugins.tooltip.bodyColor = theme.text;
    chart.options.plugins.tooltip.backgroundColor = theme.pale;

    if(isPie){

        chart.data.datasets[0].backgroundColor = [
            theme.primary,
            theme.secondary,
            theme.dark,
            theme.pale
        ];
        chart.data.datasets[0].borderColor = theme.line;

    }else{

        chart.data.datasets[0].borderColor = theme.primary;
        chart.data.datasets[0].backgroundColor = theme.primary;
        chart.options.scales.x.ticks.color = theme.text;
        chart.options.scales.y.ticks.color = theme.text;
        chart.options.scales.x.grid.color = theme.line;
        chart.options.scales.y.grid.color = theme.line;

    }

    chart.update();

}

async function loadStatistics(){

    try {

    const response = await fetch(
        "api/system_monitoring.php?action=getStatistics"
    );

    const result = await response.json();

    if(!result.success){

        showFlashMessage(
            result.message,
            result.success ? "success" : "error"
        );

        return;

    }

    document.getElementById("activeUsers").innerText =
    result.data.active_users;

    document.getElementById("totalMaterials").innerText =
    result.data.materials;

    document.getElementById("totalQuizzes").innerText =
    result.data.quizzes;

    document.getElementById("completedQuizzes").innerText =
    result.data.completed_quizzes;

    } catch (error) {

        showFlashMessage("Unable to load system statistics.", "error");

    }

}

async function loadQuizTrendChart(){

    try {

    const response = await fetch(
        "api/system_monitoring.php?action=getQuizTrend"
    );

    const result = await response.json();

    if(!result.success){

        return;

    }

    const theme = getChartTheme();

    quizTrendChart = new Chart(

        document.getElementById("quizTrendChart"),

        {

            type:"line",

            data:{

                labels:result.labels,

                datasets:[{

                    label:"Quiz Attempts",

                    data:result.data,

                    borderWidth:2,

                    borderColor:theme.primary,

                    backgroundColor:theme.primary,

                    tension:0.3,

                    fill:false

                }]

            },

            options:{

                responsive:true,

                maintainAspectRatio:false,

                plugins:{

                    legend:{ labels:{ color:theme.text } },

                    tooltip:{

                        titleColor:theme.text,

                        bodyColor:theme.text,

                        backgroundColor:theme.pale

                    }

                },

                scales:{

                    x:{

                        ticks:{ color:theme.text },

                        grid:{ color:theme.line }

                    },

                    y:{

                        ticks:{ color:theme.text },

                        grid:{ color:theme.line }

                    }

                }

            }

        }

    );

    } catch (error) {

        showFlashMessage("Unable to load quiz trend.", "error");

    }

}

async function loadSubjectDistributionChart(){

    try {

    const response = await fetch(
        "api/system_monitoring.php?action=getSubjectDistribution"
    );

    const result = await response.json();

    if(!result.success){

        return;

    }

    const theme = getChartTheme();

    subjectDistributionChart = new Chart(

        document.getElementById("subjectDistributionChart"),

        {

            type:"pie",

            data:{

                labels:result.labels,

                datasets:[{

                    label:"Quiz Count",

                    data:result.data,

                    borderWidth:2,

                    backgroundColor:[

                        theme.primary,

                        theme.secondary,

                        theme.dark,

                        theme.pale

                    ],

                    borderColor:theme.line

                }]

            },

            options:{

                responsive:true,

                maintainAspectRatio:false,

                plugins:{

                    legend:{ labels:{ color:theme.text } },

                    tooltip:{

                        titleColor:theme.text,

                        bodyColor:theme.text,

                        backgroundColor:theme.pale

                    }

                }

            }

        }

    );

    } catch (error) {

        showFlashMessage("Unable to load subject distribution.", "error");

    }

}

loadStatistics();

loadQuizTrendChart();

loadSubjectDistributionChart();

window.addEventListener("smartlearn-theme-change", ()=>{

    updateChartTheme(quizTrendChart);
    updateChartTheme(subjectDistributionChart, true);

});
