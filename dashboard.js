async function loadDashboard(){

    const response = await fetch("api/dashboard.php");

    const result = await response.json();

    if(!result.success){

        showFlashMessage(
            result.message,
            result.success ? "success" : "error"
        );

        return;

    }

    document.getElementById("totalUsers").innerText =
    result.data.totalUsers;

    document.getElementById("totalStudents").innerText =
    result.data.totalStudents;

    document.getElementById("totalTeachers").innerText =
    result.data.totalTeachers;

    document.getElementById("totalSubjects").innerText =
    result.data.totalSubjects;


    const activityList =
    document.getElementById("recentActivityList");

    activityList.innerHTML = "";

    result.data.activities.forEach(activity=>{

        activityList.innerHTML += `

        <div class="activity-item">

            <div class="activity-icon">

                <i class="fa-solid fa-clock"></i>

            </div>

            <div class="activity-content">

                <h4>${activity.activity_type}</h4>

                <p>${activity.activity_description}</p>

                <div class="activity-time">

                    ${activity.activity_date}

                </div>

            </div>

        </div>

        `;

    });

}

loadDashboard();