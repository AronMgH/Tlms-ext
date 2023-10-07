jQuery(document).ready(function($) {
    $('#get-users-button').click(function() {
        var courseId = $('#course-select').val();
        var search = $('#search-user').val(); // Get the value of the search input field
        
        if(!courseId){
            $('#error-text').show();
            setTimeout(() => {
                $('#error-text').hide();
            }, 3000);
            return;
        }

        $('#student-table').hide()
        $('#loading').show()

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'get_students',
                course_id: courseId,
                filter: search, // Send the search query
            },
            success: function(response) {
                $('#student-table').show()
                $('#student-table').html(response);
                $('#loading').hide()
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.log(textStatus, errorThrown)
                $('#loading').hide()
                $('#student-table').html(
                    `
                    <div class="empty-div text-error flex">
                        An error has occured while trying to fetch students. please check if you have working internet connection.
                    </div>
                    `
                )
            }
        });
    });

    $('.close_request').click(function() {
        var request_id = $(this).data('request-id');
        var $row = $(this).closest('tr'); // Select the table row

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'close_course_access_request',
                request_id: request_id,
            },
            success: function( response) {
                console.log('Request closed successfully.')
                $row.remove()
            }
        })
    })
});
